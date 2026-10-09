<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->boolean('portal_enabled')->default(false);
        });
        Schema::table('lead_suppliers', function (Blueprint $t) {
            $t->timestampTz('portal_invited_at')->nullable();   // the supplier was sent the portal link for this lead
        });

        // Supplier staff who may sign in to the portal. A different world from `users`: no roles, no ERP access.
        Schema::create('supplier_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $t->string('name')->default('');
            $t->string('email');
            $t->boolean('active')->default(true);
            $t->timestampTz('last_login_at')->nullable();
            $t->timestampsTz();
            $t->unique('email');
        });

        // One-time sign-in codes (only a hash is stored).
        Schema::create('supplier_login_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_user_id')->constrained('supplier_users')->cascadeOnDelete();
            $t->string('code_hash');
            $t->timestampTz('expires_at');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestampTz('used_at')->nullable();
            $t->timestampTz('created_at')->nullable();
            $t->index(['supplier_user_id', 'used_at']);
        });

        Schema::create('supplier_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_user_id')->constrained('supplier_users')->cascadeOnDelete();
            $t->string('token_hash')->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('last_seen_at')->nullable();
            $t->string('ip', 64)->nullable();
            $t->timestampTz('created_at')->nullable();
        });

        // Every quote (or refusal) a supplier submits through the portal; a revision supersedes the one before.
        Schema::create('supplier_quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_supplier_id')->constrained('lead_suppliers')->cascadeOnDelete();
            $t->string('lead_id')->index();
            $t->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $t->foreignId('supplier_user_id')->nullable()->constrained('supplier_users')->nullOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 16)->default('submitted');     // submitted | declined | superseded
            $t->double('amount')->nullable();
            $t->string('currency', 8)->nullable();
            $t->string('hotel_offered')->nullable();
            $t->boolean('flight_included')->nullable();
            $t->boolean('transfer_included')->nullable();
            $t->string('activities_included')->nullable();
            $t->date('valid_until')->nullable();
            $t->text('remarks')->nullable();
            $t->text('decline_reason')->nullable();
            $t->foreignId('reply_id')->nullable()->constrained('supplier_replies')->nullOnDelete();   // files live on this reply
            $t->timestampsTz();
            $t->index(['lead_supplier_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_quotes');
        Schema::dropIfExists('supplier_sessions');
        Schema::dropIfExists('supplier_login_codes');
        Schema::dropIfExists('supplier_users');
        Schema::table('lead_suppliers', fn (Blueprint $t) => $t->dropColumn('portal_invited_at'));
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('portal_enabled'));
    }
};

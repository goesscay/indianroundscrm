<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Trip plans, phase 3: customer self-upload links, document reminders, and supplier / cost on checklist items. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_upload_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();                 // only the hash is stored; the link itself is shown once
            $t->timestampTz('expires_at');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('last_used_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampTz('created_at')->nullable();
            $t->index('deal_id');
        });
        Schema::create('trip_doc_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $t->string('to_list');
            $t->string('status', 20);
            $t->string('trigger', 8)->default('manual');            // manual|auto
            $t->smallInteger('days_before')->nullable();
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->nullable();
            $t->index('deal_id');
        });
        Schema::table('trip_tasks', function (Blueprint $t) {
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->double('cost')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trip_tasks', fn (Blueprint $t) => $t->dropConstrainedForeignId('supplier_id'));
        Schema::table('trip_tasks', fn (Blueprint $t) => $t->dropColumn('cost'));
        Schema::dropIfExists('trip_doc_reminders');
        Schema::dropIfExists('trip_upload_links');
    }
};

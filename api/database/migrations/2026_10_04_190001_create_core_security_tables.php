<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per SPA login (equivalent of the old `sessions` collection / JWT jti).
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('jti', 64)->unique();                     // public handle, stored inside the Laravel session
            $table->string('session_id', 255)->nullable()->index();  // Laravel session id
            $table->string('ip', 64)->default('');
            $table->string('user_agent', 250)->default('');
            $table->string('device', 100)->default('');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('last_active_at')->useCurrent();
            $table->boolean('revoked')->default(false)->index();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 50)->nullable();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique(); // sha256 of the emailed token
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->timestampTz('expires_at');
            $table->boolean('used')->default(false);
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('security_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_email')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('event', 60)->index();
            $table->string('ip', 64)->default('');
            $table->string('user_agent', 200)->default('');
            $table->jsonb('extra')->nullable();
            $table->timestampTz('timestamp')->useCurrent()->index();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_email')->nullable();
            $table->string('user_name')->nullable();
            $table->string('action', 80)->index();
            $table->string('entity', 60)->index();
            $table->string('entity_id', 100)->default('');
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->timestampTz('timestamp')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('security_audit_log');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('user_sessions');
    }
};

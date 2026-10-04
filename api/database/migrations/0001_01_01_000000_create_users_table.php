<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 20)->default('sales')->index(); // admin | sales | operations
            $table->string('staff_code', 50)->default('');
            $table->string('consultant_code', 2)->default('');
            $table->string('mobile', 30)->nullable();
            $table->boolean('active')->default(true);
            // active | inactive | suspended | locked | password_reset_required
            $table->string('account_status', 30)->default('active')->index();
            $table->unsignedInteger('token_version')->default(0);
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('last_logout_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};

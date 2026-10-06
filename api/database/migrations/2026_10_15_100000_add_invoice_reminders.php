<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedSmallInteger('reminder_count')->default(0);
            $t->timestampTz('last_reminder_at')->nullable();
        });
        Schema::table('invoice_emails', function (Blueprint $t) {
            $t->string('kind', 12)->default('invoice');   // invoice|reminder
            $t->string('trigger', 8)->default('manual');  // manual|auto
        });
    }

    public function down(): void
    {
        Schema::table('invoice_emails', fn (Blueprint $t) => $t->dropColumn(['kind', 'trigger']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['reminder_count', 'last_reminder_at']));
    }
};

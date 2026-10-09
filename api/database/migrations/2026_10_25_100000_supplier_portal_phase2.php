<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_suppliers', function (Blueprint $t) {
            $t->date('respond_by')->nullable();                       // the day the team needs this supplier's quote
            $t->unsignedSmallInteger('portal_reminders')->default(0);
            $t->timestampTz('portal_reminded_at')->nullable();
        });

        // The conversation between the team and one supplier about one enquiry.
        Schema::create('supplier_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_supplier_id')->constrained('lead_suppliers')->cascadeOnDelete();
            $t->string('direction', 8);                               // supplier | team
            $t->text('body');
            $t->foreignId('supplier_user_id')->nullable()->constrained('supplier_users')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('supplier_read_at')->nullable();           // a team message the supplier has opened
            $t->timestampTz('created_at')->nullable();
            $t->index(['lead_supplier_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_messages');
        Schema::table('lead_suppliers', fn (Blueprint $t) => $t->dropColumn(['respond_by', 'portal_reminders', 'portal_reminded_at']));
    }
};

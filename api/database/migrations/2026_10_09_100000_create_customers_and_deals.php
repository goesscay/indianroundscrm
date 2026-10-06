<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('customer_no')->unique();
            $t->string('type')->default('individual'); // individual|family|corporate|agent
            $t->string('status')->default('active');   // active|inactive
            $t->string('name');
            $t->string('company')->nullable();
            $t->string('phone')->nullable();
            $t->string('phone_norm')->nullable()->index();
            $t->string('whatsapp')->nullable();
            $t->string('email')->nullable();
            $t->string('email_norm')->nullable()->index();
            $t->string('gstin', 20)->nullable();
            $t->string('address_line')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('pincode', 12)->nullable();
            $t->string('country')->nullable();
            $t->string('source')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('name');
        });

        Schema::create('customer_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $t->string('name');
            $t->string('designation')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->unsignedSmallInteger('position')->default(0);
        });

        Schema::create('customer_travellers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $t->string('name');
            $t->string('relation')->nullable();
            $t->string('gender', 20)->nullable();
            $t->date('dob')->nullable();
            $t->string('nationality')->nullable();
            $t->text('passport_no')->nullable(); // encrypted cast
            $t->date('passport_expiry')->nullable();
            $t->text('preferences')->nullable();
            $t->unsignedSmallInteger('position')->default(0);
        });

        Schema::create('deals', function (Blueprint $t) {
            $t->id();
            $t->string('deal_no')->unique();
            $t->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $t->string('lead_id')->nullable();
            $t->foreign('lead_id')->references('lead_id')->on('leads')->nullOnDelete()->cascadeOnUpdate();
            $t->string('name');
            $t->string('stage')->default('enquiry'); // enquiry|quoted|negotiation|won|lost
            $t->string('trip_status')->nullable();   // confirmed|docs_collected|travelling|completed (won deals)
            $t->unsignedSmallInteger('probability')->default(10);
            $t->string('destination')->nullable();
            $t->date('travel_date')->nullable();
            $t->unsignedInteger('duration_days')->nullable();
            $t->unsignedInteger('adults')->default(1);
            $t->unsignedInteger('children')->default(0);
            $t->double('selling_amount')->default(0);
            $t->double('supplier_cost')->default(0);
            $t->date('expected_close')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->string('lost_reason')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('customer_id');
            $t->index('stage');
            $t->index('owner_id');
            $t->index('expected_close');
        });

        Schema::create('crm_notes', function (Blueprint $t) {
            $t->id();
            $t->string('entity_type', 20); // customer|deal
            $t->unsignedBigInteger('entity_id');
            $t->text('note');
            $t->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('created_by_name')->nullable();
            $t->timestampTz('created_at')->nullable();
            $t->index(['entity_type', 'entity_id']);
        });

        Schema::table('leads', function (Blueprint $t) {
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->unsignedBigInteger('deal_id')->nullable()->index();
            $t->timestampTz('converted_at')->nullable();
        });

        Schema::table('activities', function (Blueprint $t) {
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->unsignedBigInteger('deal_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('activities', fn (Blueprint $t) => $t->dropColumn(['customer_id', 'deal_id']));
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn(['customer_id', 'deal_id', 'converted_at']));
        Schema::dropIfExists('crm_notes');
        Schema::dropIfExists('deals');
        Schema::dropIfExists('customer_travellers');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counters', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->unsignedInteger('seq')->default(0);
        });

        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('lead_id')->unique();
            $t->string('customer_name');
            $t->string('phone')->index();
            $t->string('email')->default('');
            $t->string('lead_date')->nullable();
            $t->string('travel_date')->default('');
            $t->unsignedInteger('duration_days')->nullable();
            $t->string('trip_end_date')->nullable();
            $t->string('duration_label')->nullable();
            $t->text('stay_summary')->nullable();
            $t->string('destination')->index();
            $t->string('hotel_category')->default('');
            $t->string('meal_plan')->default('');
            $t->string('package_type')->default('');
            $t->string('budget')->default('');
            $t->unsignedInteger('adults')->default(1);
            $t->unsignedInteger('children')->default(0);
            $t->string('child_ages')->default('');
            $t->unsignedInteger('rooms')->default(1);
            $t->string('room_type')->default('');
            $t->string('departure_from')->default('');
            $t->text('special_requests')->default('');
            $t->string('lead_source')->default('');
            $t->boolean('passport_available')->nullable();
            $t->string('passport_reason')->default('');
            $t->unsignedBigInteger('staff_id')->nullable()->index();
            $t->string('staff_name')->default('');
            $t->string('staff_code')->default('');
            $t->string('lead_status')->default('New Lead')->index();
            $t->string('mail_status')->default('Not Sent');
            $t->timestampTz('supplier_mail_date')->nullable();
            $t->text('supplier_name')->nullable();
            $t->text('remarks')->default('');
            $t->double('supplier_quote_amount')->default(0);
            $t->double('selling_amount')->default(0);
            $t->boolean('quote_received')->default(false);
            $t->string('quote_received_date')->nullable();
            $t->boolean('quote_sent_to_customer')->default(false);
            $t->string('quote_sent_date')->nullable();
            $t->string('booking_status')->default('');
            $t->double('booking_amount_received')->default(0);
            $t->string('follow_up_date')->nullable();
            $t->string('payment_status')->default('');
            $t->string('quote_type')->nullable();
            $t->string('package_selected')->nullable();
            $t->string('rsq_status')->nullable();
            $t->string('updated_by')->nullable();
            $t->timestampsTz();
        });

        Schema::create('lead_stay_cities', function (Blueprint $t) {
            $t->id();
            $t->string('lead_id')->index();
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('city');
            $t->unsignedInteger('nights')->nullable();
            $t->foreign('lead_id')->references('lead_id')->on('leads')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('lead_notes', function (Blueprint $t) {
            $t->id();
            $t->string('lead_id')->index();
            $t->text('note');
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->string('created_by')->default('');
            $t->timestampTz('created_at')->nullable();
            $t->foreign('lead_id')->references('lead_id')->on('leads')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('contact_events', function (Blueprint $t) {
            $t->id();
            $t->string('lead_id')->index();
            $t->string('kind');
            $t->string('channel')->default('customer');
            $t->string('target')->default('');
            $t->text('note')->default('');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_name')->default('');
            $t->timestampTz('timestamp')->nullable();
            $t->foreign('lead_id')->references('lead_id')->on('leads')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('duplicate_log', function (Blueprint $t) {
            $t->id();
            $t->string('phone');
            $t->string('original_lead_id')->nullable();
            $t->string('created_by')->nullable();
            $t->timestampTz('timestamp')->nullable();
        });

        Schema::create('rsq_requests', function (Blueprint $t) {
            $t->id();
            $t->string('lead_id')->unique();
            $t->string('status')->default('new')->index();
            $t->string('requested_by')->nullable();
            $t->string('requested_by_name')->nullable();
            $t->timestampTz('requested_at')->nullable();
            $t->text('note')->default('');
            $t->timestampTz('sent_at')->nullable();
            $t->timestampTz('first_reply_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->foreign('lead_id')->references('lead_id')->on('leads')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('lead_suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('lead_id')->index();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->string('supplier_name')->default('');
            $t->string('supplier_email')->default('');
            $t->unsignedInteger('sequence')->default(1);
            $t->string('status')->default('Email Sent');
            $t->unsignedInteger('emails_sent')->default(1);
            $t->timestampTz('first_email_at')->nullable();
            $t->timestampTz('last_email_at')->nullable();
            $t->boolean('reply_received')->default(false);
            $t->timestampTz('reply_date')->nullable();
            $t->boolean('quote_received')->default(false);
            $t->double('quote_amount')->nullable();
            $t->string('currency')->nullable();
            $t->string('hotel_offered')->nullable();
            $t->boolean('flight_included')->nullable();
            $t->boolean('transfer_included')->nullable();
            $t->string('activities_included')->nullable();
            $t->string('valid_until')->nullable();
            $t->text('remarks')->nullable();
            $t->unsignedInteger('followups')->default(0);
            $t->timestampTz('last_followup_at')->nullable();
            $t->timestampTz('last_update')->nullable();
            $t->string('updated_by')->nullable();
            $t->text('inbound_snippet')->nullable();
            $t->timestampTz('created_at')->nullable();
            $t->unique(['lead_id', 'supplier_id']);
            $t->foreign('lead_id')->references('lead_id')->on('leads')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('mail_history', function (Blueprint $t) {
            $t->id();
            $t->string('lead_id')->index();
            $t->string('subject');
            $t->text('body');
            $t->string('sent_by')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->string('status')->nullable();
            $t->string('provider')->nullable();
            $t->string('message_id')->nullable()->index();
            $t->text('error')->nullable();
            $t->string('delivery_status')->nullable();
            $t->timestampTz('opened_at')->nullable();
            $t->string('webhook_last_event')->nullable();
            $t->timestampTz('webhook_last_at')->nullable();
        });

        Schema::create('mail_history_recipients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('mail_history_id')->index();
            $t->string('type'); // to | cc | bcc
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email');
            $t->foreign('mail_history_id')->references('id')->on('mail_history')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['mail_history_recipients', 'mail_history', 'lead_suppliers', 'rsq_requests', 'duplicate_log',
                  'contact_events', 'lead_notes', 'lead_stay_cities', 'leads', 'counters'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};

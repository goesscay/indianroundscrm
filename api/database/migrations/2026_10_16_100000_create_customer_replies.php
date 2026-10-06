<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // identity of the mail we sent, so a customer's reply can be traced back to the document
        Schema::table('quote_emails', function (Blueprint $t) {
            $t->string('internet_message_id', 512)->nullable();
            $t->string('conversation_id', 512)->nullable();
            $t->index('conversation_id');
        });
        Schema::table('invoice_emails', function (Blueprint $t) {
            $t->string('internet_message_id', 512)->nullable();
            $t->string('conversation_id', 512)->nullable();
            $t->index('conversation_id');
        });

        Schema::create('customer_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $t->string('graph_message_id', 512)->unique();
            $t->string('internet_message_id', 512)->nullable();
            $t->string('conversation_id', 512)->nullable();
            $t->string('from_email')->default('');
            $t->string('from_name')->default('');
            $t->string('subject', 998)->default('');
            $t->text('body_text')->default('');
            $t->string('body_preview', 300)->default('');
            $t->timestampTz('received_at');
            $t->string('matched_by', 12);              // thread|reference|subject|sender
            $t->timestampTz('notified_at')->nullable();
            $t->unsignedBigInteger('activity_id')->nullable();
            $t->timestampsTz();
            $t->index('customer_id');
            $t->index('deal_id');
            $t->index('quote_id');
            $t->index('invoice_id');
            $t->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_replies');
        Schema::table('invoice_emails', fn (Blueprint $t) => $t->dropColumn(['internet_message_id', 'conversation_id']));
        Schema::table('quote_emails', fn (Blueprint $t) => $t->dropColumn(['internet_message_id', 'conversation_id']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_history', function (Blueprint $t) {
            $t->string('graph_message_id', 512)->nullable()->index();
            $t->string('internet_message_id', 512)->nullable()->index();
            $t->string('conversation_id', 512)->nullable()->index();
        });

        Schema::table('lead_suppliers', function (Blueprint $t) {
            $t->string('conversation_id', 512)->nullable()->index();
            // null | 'bounced' (set when a bounce / NDR for this supplier's address is received)
            $t->string('delivery_status')->nullable();
        });

        Schema::create('supplier_replies', function (Blueprint $t) {
            $t->id();
            $t->string('lead_id')->nullable();
            $t->foreign('lead_id')->references('lead_id')->on('leads')->nullOnDelete()->cascadeOnUpdate();
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->foreignId('lead_supplier_id')->nullable()->constrained('lead_suppliers')->nullOnDelete();
            $t->foreignId('mail_history_id')->nullable()->constrained('mail_history')->nullOnDelete();
            $t->string('graph_message_id', 512)->unique();
            $t->string('internet_message_id', 512)->nullable();
            $t->string('conversation_id', 512)->nullable();
            $t->text('in_reply_to')->nullable();
            $t->string('from_email')->default('');
            $t->string('from_name')->default('');
            $t->jsonb('to_emails')->nullable();
            $t->jsonb('cc_emails')->nullable();
            $t->string('subject', 998)->default('');
            $t->text('body_text')->default('');
            $t->string('body_preview', 300)->default('');
            $t->timestampTz('received_at');
            $t->string('status')->default('unmatched'); // matched|unmatched|auto_reply|bounce|ignored
            $t->string('matched_by')->nullable();        // thread|reference|subject|sender|manual
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('handled_at')->nullable();
            $t->timestampTz('notified_at')->nullable();  // side effects (tracker/notify/activity) done exactly once
            $t->unsignedBigInteger('activity_id')->nullable();
            $t->timestampsTz();

            $t->index('lead_id');
            $t->index('status');
            $t->index('received_at');
            $t->index('conversation_id');
        });

        Schema::create('supplier_reply_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reply_id')->constrained('supplier_replies')->cascadeOnDelete();
            $t->string('filename');
            $t->string('content_type')->default('');
            $t->unsignedBigInteger('size')->default(0);
            $t->string('storage_path')->nullable();
            $t->boolean('skipped')->default(false);
            $t->string('skip_reason')->nullable();
            $t->timestampsTz();
        });

        Schema::create('mail_sync_state', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestampTz('updated_at')->nullable();
        });

        // one row per notified user; read_at NULL = unread
        Schema::create('supplier_reply_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reply_id')->constrained('supplier_replies')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestampTz('read_at')->nullable();
            $t->unique(['reply_id', 'user_id']);
            $t->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_reply_reads');
        Schema::dropIfExists('mail_sync_state');
        Schema::dropIfExists('supplier_reply_attachments');
        Schema::dropIfExists('supplier_replies');
        Schema::table('lead_suppliers', function (Blueprint $t) {
            $t->dropColumn(['conversation_id', 'delivery_status']);
        });
        Schema::table('mail_history', function (Blueprint $t) {
            $t->dropColumn(['graph_message_id', 'internet_message_id', 'conversation_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $t) {
            $t->id();
            $t->string('type', 10); // direct | group
            $t->string('name')->nullable();
            $t->string('direct_key')->nullable()->unique(); // "12:57" (sorted user ids)
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('last_message_at')->nullable();
            $t->timestampsTz();
            $t->index('last_message_at');
        });

        Schema::create('chat_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedBigInteger('last_read_message_id')->nullable();
            $t->timestampTz('created_at')->nullable();
            $t->unique(['conversation_id', 'user_id']);
            $t->index('user_id');
        });

        Schema::create('chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $t->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('body');
            $t->timestampTz('deleted_at')->nullable();
            $t->timestampTz('created_at')->nullable();
            $t->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_conversations');
    }
};

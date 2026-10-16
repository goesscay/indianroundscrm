<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->string('kind', 10)->default('text');   // text | sticker (body is then the sticker's id)
            $t->jsonb('mentions')->nullable();          // user ids tagged with @
        });

        Schema::create('chat_reactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('emoji', 16);
            $t->timestampTz('created_at')->nullable();
            $t->unique(['message_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reactions');
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropColumn(['kind', 'mentions']));
    }
};

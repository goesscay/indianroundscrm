<?php

use App\Support\CallScriptDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_scripts', function (Blueprint $t) {
            $t->id();
            // null = a team default (set by an admin); otherwise the salesperson's own version
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->string('destination', 120)->nullable();     // null = a general script, used when nothing is written for the destination
            $t->string('title', 160)->default('Script');
            $t->text('body');
            $t->timestampsTz();
            $t->index(['user_id', 'destination']);
        });

        foreach (CallScriptDefaults::all() as $s) {
            DB::table('call_scripts')->insert(['user_id' => null, 'destination' => $s['destination'], 'title' => $s['title'], 'body' => $s['body'], 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('call_scripts');
    }
};

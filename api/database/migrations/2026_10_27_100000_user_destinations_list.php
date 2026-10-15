<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // the destinations this person works, in order: the first is the primary one used wherever a single value is pre-filled
            $t->jsonb('destinations')->nullable();
        });
        foreach (DB::table('users')->whereNotNull('default_destination')->where('default_destination', '!=', '')->get(['id', 'default_destination']) as $u) {
            DB::table('users')->where('id', $u->id)->update(['destinations' => json_encode([$u->default_destination])]);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('default_destination'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('default_destination', 120)->nullable());
        foreach (DB::table('users')->whereNotNull('destinations')->get(['id', 'destinations']) as $u) {
            $first = (json_decode((string) $u->destinations, true) ?: [])[0] ?? null;
            DB::table('users')->where('id', $u->id)->update(['default_destination' => $first]);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('destinations'));
    }
};

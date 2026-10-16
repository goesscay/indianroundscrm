<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            // what the deal looked like just before "Confirm trip" booked it, so an undo can put it back
            $t->jsonb('trip_confirm_prev')->nullable();
        });

        // Operations may undo a trip confirmation
        $roleId = DB::table('roles')->where('slug', 'operations')->value('id');
        if ($roleId && ! DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'trips.revert')->exists()) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => 'trips.revert']);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'trips.revert')->delete();
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('trip_confirm_prev'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Operations can enter supplier bills (and see them), without the rest of Accounts. */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'operations')->value('id');
        if ($roleId && ! DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'finance.bills')->exists()) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => 'finance.bills']);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'operations')->value('id');
        if ($roleId) {
            DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'finance.bills')->delete();
        }
    }
};

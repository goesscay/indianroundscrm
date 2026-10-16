<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Operations can cancel a receipt (a reason is always required), so a payment entered twice can be put right without waiting for an accountant. */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'operations')->value('id');
        if ($roleId && ! DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'receipts.cancel')->exists()) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => 'receipts.cancel']);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'operations')->value('id');
        if ($roleId) {
            DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'receipts.cancel')->delete();
        }
    }
};

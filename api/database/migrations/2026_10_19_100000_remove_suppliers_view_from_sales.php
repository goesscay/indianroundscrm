<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Supplier names, mail ids and replies are for Admin and Operations only: the Sales system role loses suppliers.view. Custom roles are left as they are. */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('roles')->where('slug', 'sales')->where('is_system', true)->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $ids)->where('permission', 'suppliers.view')->delete();
    }

    public function down(): void
    {
        foreach (DB::table('roles')->where('slug', 'sales')->where('is_system', true)->pluck('id') as $id) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $id, 'permission' => 'suppliers.view']);
        }
    }
};

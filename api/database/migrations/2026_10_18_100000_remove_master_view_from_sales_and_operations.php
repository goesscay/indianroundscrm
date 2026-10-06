<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Master Data is for admins. The lead form no longer needs master.view (it reads /lead-options), so the
 * Sales and Operations system roles lose it. Custom roles are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('roles')->whereIn('slug', ['sales', 'operations'])->where('is_system', true)->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $ids)->where('permission', 'master.view')->delete();
    }

    public function down(): void
    {
        $ids = DB::table('roles')->whereIn('slug', ['sales', 'operations'])->where('is_system', true)->pluck('id');
        foreach ($ids as $id) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $id, 'permission' => 'master.view']);
        }
    }
};

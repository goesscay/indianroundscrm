<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The top-most role. Like Admin it has every permission, and on top of that only a Super Admin can manage Super Admins. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('roles')->where('slug', 'super_admin')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Super Admin', 'slug' => 'super_admin', 'is_system' => true,
                'description' => 'The top-most role: full access to everything, and the only role that can create, change or remove Super Admins. Cannot be edited.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! DB::table('users')->where('role', 'super_admin')->exists()) {
            DB::table('roles')->where('slug', 'super_admin')->delete();
        }
    }
};

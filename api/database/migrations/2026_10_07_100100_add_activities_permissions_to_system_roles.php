<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: grant the default activities.* permissions to the EXISTING system roles
 * (sales, operations). Idempotent (inserts only missing rows); never touches admin or custom roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales', 'operations'] as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }
            $wanted = array_values(array_filter(Permissions::defaults($slug), fn ($p) => str_starts_with($p, 'activities.')));
            $have = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission')->all();
            $rows = [];
            foreach (array_diff($wanted, $have) as $p) {
                $rows[] = ['role_id' => $roleId, 'permission' => $p];
            }
            if ($rows) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        // intentionally a no-op: removing permissions could revoke grants an admin has since customised
    }
};

<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Grant the default customers.*, deals.* and leads.convert permissions to the EXISTING system roles. Idempotent. */
return new class extends Migration
{
    public function up(): void
    {
        $prefixes = ['customers.', 'deals.', 'leads.convert'];
        foreach (['sales', 'operations'] as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }
            $wanted = array_values(array_filter(Permissions::defaults($slug), function ($p) use ($prefixes) {
                foreach ($prefixes as $x) {
                    if (str_starts_with($p, $x)) {
                        return true;
                    }
                }

                return false;
            }));
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

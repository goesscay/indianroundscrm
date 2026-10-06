<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_targets', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('fy');      // financial year start: 2026 = Apr 2026 - Mar 2027
            $t->unsignedTinyInteger('quarter');  // 1..4 (Apr-Jun, Jul-Sep, Oct-Dec, Jan-Mar)
            $t->unsignedBigInteger('user_id')->default(0); // 0 = whole team
            $t->double('amount')->default(0);          // revenue target
            $t->double('margin_amount')->nullable();   // optional margin target
            $t->timestampsTz();
            $t->unique(['fy', 'quarter', 'user_id']);
        });

        // grant the default forecasts.* permissions to the existing system roles (idempotent)
        foreach (['sales', 'operations'] as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }
            $wanted = array_filter(Permissions::defaults($slug), fn ($p) => str_starts_with($p, 'forecasts.'));
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
        Schema::dropIfExists('forecast_targets');
    }
};

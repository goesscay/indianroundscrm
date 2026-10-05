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
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->text('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->timestampsTz();
        });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $t->string('permission');
            $t->unique(['role_id', 'permission']);
        });

        Schema::table('users', fn (Blueprint $t) => $t->string('role', 100)->default('sales')->change());

        $now = now();
        $system = [
            'admin' => ['Admin', 'Full access to everything. Cannot be edited.'],
            'sales' => ['Sales', 'Sales consultants: work their own leads.'],
            'operations' => ['Operations', 'Operations team: supplier quotes (RSQ) and all leads.'],
        ];
        foreach ($system as $slug => [$name, $desc]) {
            $id = DB::table('roles')->insertGetId([
                'name' => $name, 'slug' => $slug, 'description' => $desc, 'is_system' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($slug !== 'admin') {
                DB::table('role_permissions')->insert(array_map(
                    fn ($p) => ['role_id' => $id, 'permission' => $p], Permissions::defaults($slug)
                ));
            }
        }

        // Keep any pre-existing user with an unexpected role slug working (as a custom role without permissions).
        foreach (DB::table('users')->distinct()->pluck('role') as $slug) {
            if ($slug && ! isset($system[$slug])) {
                DB::table('roles')->insert([
                    'name' => ucwords(str_replace(['-', '_'], ' ', $slug)), 'slug' => $slug, 'is_system' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};

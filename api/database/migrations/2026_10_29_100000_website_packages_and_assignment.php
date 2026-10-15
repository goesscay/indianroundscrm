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
        Schema::table('packages', function (Blueprint $t) {
            $t->string('source', 16)->default('local');               // local | website
            $t->string('source_ref', 190)->nullable();                 // the tour's slug on the website
            $t->double('source_price')->nullable();                    // the price the website shows; the adult price follows it while set
            $t->string('source_hash', 40)->nullable();                 // what the last sync wrote, so unchanged tours are left alone
            $t->timestampTz('source_synced_at')->nullable();
            $t->timestampTz('source_removed_at')->nullable();          // the tour is no longer on the website
            $t->boolean('source_unlocked')->default(false);            // the team took over this package: the sync leaves it alone
            $t->string('image_url', 500)->nullable();
            $t->string('website_url', 500)->nullable();
            $t->text('highlights')->nullable();
            $t->index(['source', 'source_ref']);
        });

        // the dated group departures of a website tour (shown, not priced)
        Schema::create('package_departures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $t->string('source_ref', 60);
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->integer('seats_left')->nullable();
            $t->string('status', 30)->nullable();
            $t->timestampsTz();
            $t->unique(['package_id', 'source_ref']);
            $t->index('start_date');
        });

        // which packages a salesperson may see (Operations and Admin see them all)
        Schema::create('package_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->nullable();
            $t->unique(['package_id', 'user_id']);
        });

        // Operations see every package
        $roleId = DB::table('roles')->where('slug', 'operations')->value('id');
        if ($roleId && ! DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'packages.view_all')->exists()) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => 'packages.view_all']);
        }

        // Nobody should lose the packages they use today: everyone who can use packages but cannot see all of them
        // is assigned every package that exists now. Packages that come from the website start unassigned.
        $roleIds = DB::table('roles')->pluck('id', 'slug');
        $perms = DB::table('role_permissions')->get()->groupBy('role_id')->map(fn ($g) => $g->pluck('permission')->all());
        $packageIds = DB::table('packages')->pluck('id')->all();
        foreach (DB::table('users')->where('role', '!=', 'admin')->get(['id', 'role']) as $u) {
            $p = $perms[$roleIds[$u->role] ?? 0] ?? [];
            if (in_array('packages.view_all', $p, true) || in_array('packages.manage', $p, true)) {
                continue;
            }
            if (! in_array('packages.view', $p, true) && ! in_array('quotes.create', $p, true)) {
                continue;
            }
            foreach (array_chunk($packageIds, 500) as $chunk) {
                DB::table('package_user')->insert(array_map(fn ($pid) => ['package_id' => $pid, 'user_id' => $u->id, 'assigned_by' => null, 'created_at' => now()], $chunk));
            }
        }
        Permissions::flush();
    }

    public function down(): void
    {
        Schema::dropIfExists('package_user');
        Schema::dropIfExists('package_departures');
        Schema::table('packages', function (Blueprint $t) {
            $t->dropIndex(['source', 'source_ref']);
            $t->dropColumn(['source', 'source_ref', 'source_price', 'source_hash', 'source_synced_at', 'source_removed_at', 'source_unlocked', 'image_url', 'website_url', 'highlights']);
        });
        DB::table('role_permissions')->where('permission', 'packages.view_all')->delete();
    }
};

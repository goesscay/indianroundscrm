<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transport master (Operations -> Transport): countries, places, providers, vehicles, routes and rate cards.
 * Same shape as Hotels -> Rooms -> Rates. Vendor cost is stored in the provider's own currency; the selling price is worked out later (Phase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_countries', function (Blueprint $t) {
            $t->string('code', 2)->primary();                // IN, SG ...
            $t->string('name');
            $t->string('currency', 3);                       // INR, SGD ...
            $t->string('timezone', 60)->nullable();
            $t->boolean('active')->default(true);
            $t->timestampsTz();
        });

        Schema::create('transport_places', function (Blueprint $t) {
            $t->id();
            $t->string('country_code', 2);
            $t->foreign('country_code')->references('code')->on('transport_countries')->cascadeOnUpdate();
            $t->string('name');
            $t->string('kind', 12)->default('city');         // city | airport | station | bus_stand | harbour | other
            $t->string('city')->nullable();                  // the city a pickup point belongs to
            $t->string('code', 12)->nullable();              // DEL, SIN ...
            $t->string('timezone', 60)->nullable();
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->index(['country_code', 'kind']);
        });

        Schema::create('transport_providers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('country_code', 2);
            $t->foreign('country_code')->references('code')->on('transport_countries')->cascadeOnUpdate();
            $t->string('destination');                       // Manali, Singapore ... the same names leads, packages and hotels use
            $t->string('city')->nullable();
            $t->jsonb('modes')->nullable();                  // cab | van | bus | ferry
            $t->string('currency', 3);                       // what this provider charges in
            $t->string('contact_name')->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('email')->nullable();
            $t->text('notes')->nullable();
            $t->string('margin_type', 10)->nullable();       // percent | amount; null = the destination / default margin
            $t->double('margin_value')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['destination', 'active']);
            $t->index('country_code');
        });

        Schema::create('transport_vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_id')->constrained('transport_providers')->cascadeOnDelete();
            $t->string('name');                              // Ertiga, Tempo Traveller (14 seater), Volvo seat
            $t->string('mode', 8)->default('cab');           // cab | van | bus | ferry
            $t->unsignedSmallInteger('seats')->default(4);
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->index('provider_id');
        });

        Schema::create('transport_routes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_id')->constrained('transport_providers')->cascadeOnDelete();
            $t->string('title');                             // Local Manali, Solang Valley, Kullu-Naggar
            $t->string('kind', 12)->default('sightseeing');  // sightseeing | transfer | intercity
            $t->foreignId('from_place_id')->nullable()->constrained('transport_places')->nullOnDelete();
            $t->foreignId('to_place_id')->nullable()->constrained('transport_places')->nullOnDelete();
            $t->string('duration')->nullable();
            $t->text('inclusions')->nullable();              // what the customer is told is included
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->index('provider_id');
        });

        Schema::create('transport_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_id')->constrained('transport_providers')->cascadeOnDelete();
            $t->foreignId('vehicle_id')->constrained('transport_vehicles')->cascadeOnDelete();
            $t->foreignId('route_id')->nullable()->constrained('transport_routes')->cascadeOnDelete();
            $t->string('basis', 12);                         // per_day | per_transfer | per_route | per_person
            $t->string('season')->nullable();                // Off Season, Mid Season, On Season ...
            $t->date('start_date')->nullable();              // null = the season dates are still to be given; such a rate is not used for pricing yet
            $t->date('end_date')->nullable();
            $t->double('cost');                              // vendor cost, in the provider's currency
            $t->double('child_cost')->nullable();            // per-person rates only
            $t->string('currency', 3);
            $t->timestampsTz();
            $t->index(['provider_id', 'vehicle_id', 'route_id']);
        });

        $now = now();
        DB::table('transport_countries')->insert([
            ['code' => 'IN', 'name' => 'India', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SG', 'name' => 'Singapore', 'currency' => 'SGD', 'timezone' => 'Asia/Singapore', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Operations manage transport, sales can look it up; admins already have everything
        foreach (['operations' => ['transport.view', 'transport.manage'], 'sales' => ['transport.view']] as $slug => $perms) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            foreach ($roleId ? $perms : [] as $perm) {
                if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission', $perm)->exists()) {
                    DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => $perm]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', ['transport.view', 'transport.manage'])->delete();
        foreach (['transport_rates', 'transport_routes', 'transport_vehicles', 'transport_providers', 'transport_places', 'transport_countries'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

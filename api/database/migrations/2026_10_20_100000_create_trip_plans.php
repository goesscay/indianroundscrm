<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Trip plans: a confirmed deal becomes a Trip File with an operations checklist built from a template. */
return new class extends Migration
{
    /** [category, title, days before travel (negative = after), critical] */
    private function common(): array
    {
        return [
            ['payments', 'Collect the balance payment', 15, true],
            ['documents', 'Collect ID proof of all travellers', 30, true],
            ['documents', 'Confirm traveller names match their ID exactly', 21, true],
            ['documents', 'Send itinerary and vouchers to the customer', 5, true],
            ['pre_departure', 'Pre-departure briefing call with the customer', 3, false],
            ['pre_departure', 'Share emergency contact numbers with the customer', 2, true],
            ['pre_departure', 'Reminder to the customer the day before travel', 1, false],
            ['pre_departure', 'Check-in call on arrival day', 0, false],
            ['post_trip', 'Collect feedback and a review', -3, false],
            ['post_trip', 'Settle all supplier bills for this trip', -7, false],
        ];
    }

    private function templates(): array
    {
        $domestic = [
            ['transport', 'Book flight, train or bus tickets', 45, true],
            ['stay', 'Confirm hotel bookings and get vouchers', 30, true],
            ['transport', 'Arrange transfers and cabs', 14, true],
            ['activities', 'Book sightseeing and activities', 14, false],
            ['transport', 'Confirm cab or driver details with the customer', 2, false],
        ];
        $international = [
            ['visa_insurance', 'Check every passport is valid for at least 6 months after return', 45, true],
            ['transport', 'Book international flights', 45, true],
            ['visa_insurance', 'Apply for visas', 40, true],
            ['stay', 'Confirm hotel bookings and get vouchers', 30, true],
            ['visa_insurance', 'Visas approved and copies received', 14, true],
            ['visa_insurance', 'Buy travel insurance', 14, true],
            ['transport', 'Arrange airport and in-country transfers', 14, true],
            ['activities', 'Book sightseeing, tickets and activities', 14, false],
            ['forex_connectivity', 'Advise on forex and cards', 10, false],
            ['forex_connectivity', 'Arrange SIM or e-SIM', 7, false],
            ['transport', 'Online check-in and seat selection', 2, false],
        ];
        $package = [
            ['transport', 'Confirm group departure with the supplier', 30, true],
            ['stay', 'Confirm hotels and the rooming list', 14, true],
            ['transport', 'Book flights, trains or coach', 30, true],
            ['activities', 'Confirm guide, coach and sightseeing', 7, false],
            ['documents', 'Send the passenger manifest to the supplier', 14, true],
        ];

        return [
            ['Domestic trip', 'domestic', $domestic],
            ['International trip', 'international', $international],
            ['Package tour', 'package', $package],
        ];
    }

    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->string('trip_type')->nullable();                       // domestic|international|package
            $t->timestampTz('trip_confirmed_at')->nullable();          // the trip plan exists from this moment
            $t->foreignId('trip_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('trip_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('trip_type')->unique();
            $t->boolean('active')->default(true);
            $t->timestampsTz();
        });
        Schema::create('trip_template_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')->constrained('trip_templates')->cascadeOnDelete();
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('category');
            $t->string('title', 200);
            $t->smallInteger('due_days_before')->nullable();           // days before travel; negative = after the trip
            $t->boolean('critical')->default(false);
            $t->index('template_id');
        });
        Schema::create('trip_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('category');
            $t->string('title', 200);
            $t->text('notes')->nullable();
            $t->date('due_date')->nullable();
            $t->string('status')->default('pending');                  // pending|in_progress|done|na
            $t->boolean('critical')->default(false);
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['deal_id', 'status']);
            $t->index('due_date');
        });

        foreach ($this->templates() as [$name, $type, $items]) {
            $id = DB::table('trip_templates')->insertGetId(['name' => $name, 'trip_type' => $type, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $rows = [];
            foreach (array_merge($items, $this->common()) as [$cat, $title, $days, $critical]) {
                $rows[] = ['template_id' => $id, 'category' => $cat, 'title' => $title, 'due_days_before' => $days, 'critical' => $critical];
            }
            // work through the trip in time order: earliest deadline first
            usort($rows, fn ($a, $b) => $b['due_days_before'] <=> $a['due_days_before']);
            foreach ($rows as $i => $r) {
                DB::table('trip_template_items')->insert($r + ['position' => $i]);
            }
        }

        // default permissions for the existing system roles (idempotent)
        foreach (['sales', 'operations'] as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }
            $have = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission')->all();
            foreach (array_diff(array_filter(Permissions::defaults($slug), fn ($p) => str_starts_with($p, 'trips.')), $have) as $p) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => $p]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_tasks');
        Schema::dropIfExists('trip_template_items');
        Schema::dropIfExists('trip_templates');
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn(['trip_type', 'trip_confirmed_at', 'trip_confirmed_by']));
    }
};

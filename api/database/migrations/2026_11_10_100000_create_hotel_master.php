<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of the premium quotation: the hotel master (hotels, rooms, vendor rates by season, margins), a photo library,
 * and the reusable quotation content (inclusions, exclusions, cancellation policy, notes, terms, closing page).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('destination');                       // Manali, Goa, Bali ... the same names the leads and packages use
            $t->string('city')->nullable();                  // area inside the destination
            $t->unsignedTinyInteger('stars')->nullable();    // 1-5
            $t->text('address')->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('website')->nullable();
            $t->decimal('google_rating', 2, 1)->nullable();
            $t->unsignedInteger('google_reviews')->nullable();
            $t->text('description')->nullable();
            $t->jsonb('amenities')->nullable();              // list of short strings
            $t->string('margin_type', 10)->nullable();       // percent | amount (per person, whole stay); null = use the destination / default margin
            $t->double('margin_value')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('destination');
            $t->index('active');
        });

        Schema::create('hotel_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $t->string('name');                              // Deluxe Room with Balcony View
            $t->unsignedTinyInteger('max_adults')->default(2);
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->index('hotel_id');
        });

        // vendor cost per night, by meal plan and season; a night is priced from the row whose dates cover it
        Schema::create('hotel_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained('hotel_rooms')->cascadeOnDelete();
            $t->string('meal_plan', 4);                      // EP room only | CP breakfast | MAP breakfast + dinner | AP all meals
            $t->string('season')->nullable();                // "Peak", "Christmas week" ...
            $t->date('start_date');
            $t->date('end_date');
            $t->double('single')->nullable();                // one adult alone in the room, per night
            $t->double('double')->nullable();                // two adults sharing, per room per night
            $t->double('triple')->nullable();                // three adults in one room, per room per night
            $t->double('extra_adult')->nullable();           // extra bed, per night
            $t->double('child_with_bed')->nullable();
            $t->double('child_no_bed')->nullable();
            $t->timestampsTz();
            $t->index(['room_id', 'meal_plan', 'start_date']);
        });

        // photos: of a hotel, or of a destination (cover, day pictures)
        Schema::create('media', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hotel_id')->nullable()->constrained('hotels')->cascadeOnDelete();
            $t->string('destination')->nullable();
            $t->string('kind', 12)->default('other');        // hotel | destination | cover | day | other
            $t->string('caption')->nullable();
            $t->string('path');
            $t->string('original_name')->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->unsignedSmallInteger('position')->default(0);
            $t->boolean('is_cover')->default(false);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('hotel_id');
            $t->index(['destination', 'kind']);
        });

        // reusable text for the quotation: lists of lines (inclusions, exclusions, notes, terms) and cancellation tiers
        Schema::create('quote_content_blocks', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 14);                          // inclusions | exclusions | cancellation | notes | terms | closing
            $t->string('title');
            $t->string('destination')->nullable();           // null = every destination
            $t->jsonb('items')->nullable();                  // list of lines
            $t->jsonb('tiers')->nullable();                  // cancellation: [{label, percent}]
            $t->boolean('is_default')->default(false);       // pre-selected on a new quotation
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestampsTz();
            $t->index(['kind', 'destination']);
        });

        Schema::create('quote_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->jsonb('value')->nullable();
            $t->timestampTz('updated_at')->nullable();
        });

        $now = now();
        DB::table('quote_settings')->insert([
            ['key' => 'margin_default', 'value' => json_encode(['type' => 'percent', 'value' => 15]), 'updated_at' => $now],
            ['key' => 'margin_destinations', 'value' => json_encode(new stdClass), 'updated_at' => $now],     // {"Manali": {"type": "percent", "value": 12}}
        ]);

        $block = fn (string $kind, string $title, ?array $items, ?array $tiers = null, int $pos = 0) => [
            'kind' => $kind, 'title' => $title, 'items' => $items === null ? null : json_encode($items), 'tiers' => $tiers === null ? null : json_encode($tiers),
            'is_default' => true, 'active' => true, 'position' => $pos, 'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('quote_content_blocks')->insert([
            $block('exclusions', 'Standard exclusions', [
                'GST 5%.', 'Air fare / train fare.',
                'Personal expenses such as laundry, telephone calls, tips and gratuity, mineral water, soft and hard drinks, rafting, rock climbing, paragliding, joy rides, porterage.',
                'Additional sightseeing or extra usage of vehicles, other than mentioned in the itinerary.', 'Entrance fees and guide charges.',
                'Any cost arising due to natural calamities like landslides, road blockage, political disturbances (strikes), etc. (to be borne by the client, directly payable on the spot).',
                'Any increase in taxes or fuel price, leading to an increase in cost on surface transportation and land arrangements, which may come into effect prior to departure.',
                'Room heater.',
            ], null, 1),
            $block('cancellation', 'Standard cancellation policy', [
                'Cancellation requests must be made in writing via email or WhatsApp.',
                'Flight tickets, visa fees, travel insurance and supplier charges are non-refundable as per their respective policies.',
                'Eligible refunds will be processed within 15-31 working days after applicable deductions.',
            ], [
                ['label' => '50+ days before departure', 'percent' => 25], ['label' => '35-49 days before departure', 'percent' => 50],
                ['label' => '20-34 days before departure', 'percent' => 75], ['label' => 'Less than 20 days before departure / no show', 'percent' => 100],
            ], 1),
            $block('notes', 'Standard notes', [
                'Check-in and check-out timings will be as per the respective hotel policy.',
                'Early check-in or late check-out is subject to availability and may incur extra charges.',
                'Rooms are subject to availability at the time of booking.',
                'Meal plan will be strictly as mentioned in the itinerary; no meals are included unless specified.',
                'The vehicle will be provided strictly as per the itinerary and on a point-to-point basis only.',
                'Any changes in the itinerary due to weather conditions, road closures, or other unavoidable circumstances will be made in the best interest of the guests.',
                'Rates are subject to change in case of any increase in taxes, fuel prices, or permit charges by the government.',
                'All personal expenses such as laundry, telephone calls, tips, mineral water, and beverages are to be borne by the guests.',
                'Entrance fees, guide charges, camera fees, and any activity charges are not included unless specified.',
            ], null, 1),
            $block('terms', 'Standard terms and conditions', [
                'Any additional sightseeing or extra usage of vehicle not mentioned in the itinerary will be chargeable.',
                'The company will not be responsible for any loss, theft, or damage to personal belongings.',
                'Guests are requested to carry valid photo identification during travel.',
                'No refund will be provided for unused services or meals.',
                'Booking confirmation is subject to receipt of advance payment.',
                'Anything not specifically mentioned under "Inclusions" will be treated as excluded.',
            ], null, 1),
            $block('closing', 'Closing page', [
                "Let's make this trip happen.", 'Your dream trip getaway is just one step away.', 'Travel beyond borders.',
            ], null, 1),
        ]);

        // who may do what
        foreach ([['sales', ['hotels.view']], ['operations', ['hotels.view', 'hotels.manage']]] as [$slug, $perms]) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            foreach ($perms as $p) {
                if ($roleId && ! DB::table('role_permissions')->where('role_id', $roleId)->where('permission', $p)->exists()) {
                    DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => $p]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', ['hotels.view', 'hotels.manage'])->delete();
        Schema::dropIfExists('quote_settings');
        Schema::dropIfExists('quote_content_blocks');
        Schema::dropIfExists('media');
        Schema::dropIfExists('hotel_rates');
        Schema::dropIfExists('hotel_rooms');
        Schema::dropIfExists('hotels');
    }
};

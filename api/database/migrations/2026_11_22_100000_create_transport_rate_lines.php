<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations -> Transport -> Rates as one list: one line per rate (type, destination, service name, price, charged as), with the
 * supplier, season dates, seats and hours optional. Replaces the Countries / Places / Providers / Rate cards setup. Flights and trains are not
 * here (they are details-only lists). Destination, supplier and place names are typed and remembered, not set up first.
 */
return new class extends Migration
{
    public function up(): void
    {
        // the same destination names Hotels use; the country and currency come with the destination so nobody is asked for them on every rate
        Schema::create('transport_destinations', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120)->unique();
            $t->string('country', 100)->default('India');
            $t->string('currency', 3)->default('INR');
            $t->timestampsTz();
        });

        // a supplier is just a name typed on a rate; the list builds itself
        Schema::create('transport_suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150)->unique();
            $t->string('phone', 60)->nullable();
            $t->text('note')->nullable();
            $t->timestampsTz();
        });

        Schema::create('transport_lines', function (Blueprint $t) {
            $t->id();
            $t->string('type', 6);                                       // cab | van | bus | ferry
            $t->foreignId('destination_id')->constrained('transport_destinations')->cascadeOnUpdate();
            $t->foreignId('supplier_id')->nullable()->constrained('transport_suppliers')->nullOnDelete();
            $t->string('name', 200);                                     // "Ertiga, 6 seats: local sightseeing"
            $t->string('from_name', 120)->nullable();                    // bus, ferry and transfers: typed with suggestions
            $t->string('to_name', 120)->nullable();
            $t->string('way', 10)->nullable();                           // one_way | return (for a route)
            $t->string('charged', 10);                                   // per_day | per_trip | per_person
            $t->double('price')->nullable();                             // in the line's currency; empty = "needs a price"
            $t->string('currency', 3);
            $t->string('season_name')->nullable();                       // the name of this price: "Off season"; empty = all year
            $t->date('valid_from')->nullable();                          // empty dates = the price applies all year
            $t->date('valid_to')->nullable();
            $t->unsignedSmallInteger('seats')->nullable();
            $t->double('hours_per_day')->nullable();
            $t->unsignedInteger('km_included')->nullable();
            $t->double('extra_hour')->nullable();
            $t->text('note')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['destination_id', 'type']);
            $t->index('active');
        });

        // more seasons of the same service: different dates, different price, still one line
        Schema::create('transport_line_seasons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('line_id')->constrained('transport_lines')->cascadeOnDelete();
            $t->string('name', 80);
            $t->date('valid_from')->nullable();                          // a season without dates is kept but not used for pricing yet
            $t->date('valid_to')->nullable();
            $t->double('price');
            $t->timestampsTz();
            $t->index('line_id');
        });
    }

    public function down(): void
    {
        foreach (['transport_line_seasons', 'transport_lines', 'transport_suppliers', 'transport_destinations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Operations -> Transport: a rate gets a Car name ("Ertiga", "Volvo B11R"); the old Service name is now shown as the Route service. Old rates keep their data, the car name starts empty. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_lines', function (Blueprint $t) {
            $t->string('car_name', 120)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('transport_lines', function (Blueprint $t) {
            $t->dropColumn('car_name');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flights and trains (Operations -> Transport -> Flights / Trains): scheduled services, details only.
 * There is deliberately no price column: these are suggestions shown to the customer and are never part of any calculation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_schedules', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 6);                           // flight | train
            $t->string('title');                             // "Air India AI 123", "Shatabdi 12011"
            $t->string('from_name');                         // Delhi, New Delhi
            $t->string('from_code', 12)->nullable();         // DEL, NDLS
            $t->string('to_name');                           // Kullu-Manali, Kalka
            $t->string('to_code', 12)->nullable();           // KUU, KLK
            $t->string('serves')->nullable();                // places it gets you to when it ends near them: "Manali, Shimla"
            $t->string('departs', 5)->nullable();           // 07:40
            $t->string('arrives', 5)->nullable();            // 11:45
            $t->boolean('arrives_next_day')->default(false);
            $t->jsonb('days');                               // ["mon","tue",...] the days the service runs
            $t->string('classes')->nullable();               // "CC, EC" or "Economy"
            $t->text('note')->nullable();                    // how to continue: "Arrives at Kalka. Taxi or bus onward to Manali"
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();                              // updated_at is the "updated on" date Operations review
            $t->index(['kind', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_schedules');
    }
};

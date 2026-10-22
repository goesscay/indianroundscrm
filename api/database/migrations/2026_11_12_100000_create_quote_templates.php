<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a ready-made premium quotation (itinerary, pictures, land package, hotel slots, text) that sales start from
        Schema::create('quote_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('destination');
            $t->string('tagline')->nullable();
            $t->jsonb('payload');                            // the proposal; option stays are empty slots (nights, meal plan, a star hint)
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('position')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['destination', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_templates');
    }
};

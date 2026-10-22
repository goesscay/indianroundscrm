<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            // the premium proposal: trip, itinerary days with photos, hotel options, land package, and the policy text.
            // null for ordinary line-by-line quotes. The quote lines are worked out from it.
            $t->jsonb('proposal')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn('proposal'));
    }
};

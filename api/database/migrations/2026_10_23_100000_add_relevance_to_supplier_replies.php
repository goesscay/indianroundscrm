<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_replies', function (Blueprint $t) {
            // normal | quote (has a file or a price) | chatter (internal forward / follow-up noise, handled silently)
            $t->string('relevance', 16)->default('normal');
            // the one reply the team pinned as the supplier's real quote (at most one per supplier tracker)
            $t->boolean('is_final')->default(false);
            $t->index(['lead_supplier_id', 'is_final']);
        });
    }

    public function down(): void
    {
        Schema::table('supplier_replies', function (Blueprint $t) {
            $t->dropIndex(['lead_supplier_id', 'is_final']);
            $t->dropColumn(['relevance', 'is_final']);
        });
    }
};

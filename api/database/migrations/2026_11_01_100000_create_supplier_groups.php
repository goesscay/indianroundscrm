<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a named set of suppliers (usually "everyone who handles Vietnam"), picked as one unit when sending a supplier mail
        Schema::create('supplier_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('destination')->nullable();
            $t->timestampsTz();
        });

        Schema::create('supplier_group_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_group_id')->constrained('supplier_groups')->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $t->unique(['supplier_group_id', 'supplier_id']);
            $t->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_group_members');
        Schema::dropIfExists('supplier_groups');
    }
};

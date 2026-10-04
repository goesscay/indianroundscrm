<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 50)->default('');
            $table->string('destination')->default('');
            $table->string('country')->default('');
            $table->timestampsTz();
        });

        Schema::create('master', function (Blueprint $table) {
            $table->id();
            $table->string('category', 60);
            $table->string('value');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['category', 'value']);
        });

        Schema::create('destination_cities', function (Blueprint $table) {
            $table->id();
            $table->string('destination')->unique();
            $table->timestampsTz();
        });

        Schema::create('destination_city_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_city_id')->constrained('destination_cities')->cascadeOnDelete();
            $table->string('city');
            $table->unsignedInteger('position')->default(0);
            $table->index(['destination_city_id', 'position']);
        });

        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->string('destination')->unique();
            $table->foreignId('consultant_id')->constrained('users')->cascadeOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
        Schema::dropIfExists('destination_city_items');
        Schema::dropIfExists('destination_cities');
        Schema::dropIfExists('master');
        Schema::dropIfExists('suppliers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->string('type')->default('task'); // task|call|meeting|email|follow_up
            $t->string('title', 200);
            $t->text('details')->nullable();
            $t->string('priority')->default('medium'); // low|medium|high
            $t->timestampTz('due_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('lead_id')->nullable();
            $t->foreign('lead_id')->references('lead_id')->on('leads')->nullOnDelete()->cascadeOnUpdate();
            $t->timestampsTz();

            $t->index('assigned_to');
            $t->index('lead_id');
            $t->index('due_at');
            $t->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};

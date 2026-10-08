<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Trip documents: who is travelling, which documents each traveller must give, and the (encrypted, private) files. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_travellers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $t->foreignId('traveller_id')->constrained('customer_travellers')->cascadeOnDelete();
            $t->unique(['deal_id', 'traveller_id']);
        });
        Schema::create('trip_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $t->foreignId('traveller_id')->nullable()->constrained('customer_travellers')->cascadeOnDelete();   // null = a document for the whole trip
            $t->string('doc_type', 40);
            $t->string('status', 12)->default('pending');          // pending|received|verified|rejected|na
            $t->date('expiry_date')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('verified_at')->nullable();
            $t->timestampsTz();
            $t->index(['deal_id', 'doc_type']);
        });
        Schema::create('trip_document_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('trip_documents')->cascadeOnDelete();
            $t->string('path');                                     // on the private disk; the content is encrypted
            $t->string('original_name');
            $t->string('mime', 80);
            $t->unsignedInteger('size')->default(0);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->nullable();
        });

        foreach (['sales', 'operations'] as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }
            $have = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission')->all();
            foreach (array_diff(array_filter(Permissions::defaults($slug), fn ($p) => str_starts_with($p, 'trip_docs.')), $have) as $p) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => $p]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_document_files');
        Schema::dropIfExists('trip_documents');
        Schema::dropIfExists('trip_travellers');
    }
};

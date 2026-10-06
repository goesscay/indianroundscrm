<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bills', function (Blueprint $t) {
            $t->id();
            $t->string('bill_no')->unique();                    // SB/26-27/0001 (our own number)
            $t->string('supplier_ref')->nullable();             // the supplier's invoice number
            $t->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->date('bill_date');
            $t->date('due_date')->nullable();
            $t->double('total')->default(0);
            $t->double('paid_total')->default(0);
            $t->string('status')->default('open');             // open|cancelled
            $t->text('notes')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('supplier_id');
            $t->index('deal_id');
            $t->index('status');
        });

        Schema::create('supplier_bill_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bill_id')->constrained('supplier_bills')->cascadeOnDelete();
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('description');
            $t->double('amount');
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete(); // cost / expense account
            $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->index('bill_id');
        });

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->string('payment_no')->unique();                 // SP/26-27/0001
            $t->string('status')->default('valid');             // valid|cancelled
            $t->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->foreignId('bill_id')->nullable()->constrained('supplier_bills')->nullOnDelete(); // null = advance to the supplier
            $t->double('amount');
            $t->date('paid_date');
            $t->string('mode')->default('bank_transfer');
            $t->string('reference')->nullable();
            $t->unsignedBigInteger('bank_account_id')->nullable()->index();
            $t->text('notes')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('supplier_id');
            $t->index('bill_id');
        });

        Schema::create('money_entries', function (Blueprint $t) {
            $t->id();
            $t->string('entry_no')->unique();                   // MO/26-27/0001 (money out) or MI/26-27/0001 (money in)
            $t->string('kind', 10);                             // expense|income
            $t->string('status')->default('valid');
            $t->date('entry_date');
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete(); // expense or income account
            $t->double('amount');
            $t->unsignedBigInteger('bank_account_id')->nullable()->index();
            $t->string('mode')->default('bank_transfer');
            $t->string('payee')->nullable();
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('entry_date');
        });

        Schema::table('journal_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('supplier_id')->nullable()->index();
        });

        $now = now();
        if (! DB::table('accounts')->where('code', '1300')->exists()) {
            DB::table('accounts')->insert(['code' => '1300', 'name' => 'Supplier advances', 'type' => 'asset', 'subtype' => 'supplier_advances', 'is_system' => true, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        // the Accountant role created in the previous release gets the new permission
        $roleId = DB::table('roles')->where('slug', 'accountant')->value('id');
        if ($roleId && ! DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'finance.payables')->exists()) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => 'finance.payables']);
        }
    }

    public function down(): void
    {
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropColumn('supplier_id'));
        Schema::dropIfExists('money_entries');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_bill_lines');
        Schema::dropIfExists('supplier_bills');
        DB::table('accounts')->where('code', '1300')->delete();
    }
};

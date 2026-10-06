<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** code, name, type, subtype, system */
    private const CHART = [
        ['1000', 'Cash in hand', 'asset', 'cash', true],
        ['1010', 'Bank', 'asset', 'bank', true],
        ['1100', 'Accounts receivable', 'asset', 'receivable', true],
        ['2000', 'Accounts payable', 'liability', 'payable', true],
        ['2100', 'Customer advances', 'liability', 'advances', true],
        ['2200', 'GST payable', 'liability', 'tax', true],
        ['2210', 'CGST payable', 'liability', 'tax', true],
        ['2220', 'SGST payable', 'liability', 'tax', true],
        ['2230', 'IGST payable', 'liability', 'tax', true],
        ['2240', 'TCS payable', 'liability', 'tax', true],
        ['3000', "Owner's capital", 'equity', null, false],
        ['3100', 'Retained earnings', 'equity', 'retained', true],
        ['3200', 'Opening balance equity', 'equity', 'opening', true],
        ['4000', 'Tour sales', 'income', 'sales', true],
        ['4090', 'Sales returns and cancellations', 'income', 'returns', true],
        ['4100', 'Other income', 'income', null, false],
        ['5000', 'Cost of tours (supplier costs)', 'expense', 'cost_of_sales', true],
        ['6100', 'Salaries and wages', 'expense', null, false],
        ['6200', 'Rent and utilities', 'expense', null, false],
        ['6300', 'Marketing', 'expense', null, false],
        ['6400', 'Software and subscriptions', 'expense', null, false],
        ['6500', 'Bank charges', 'expense', null, false],
        ['6600', 'Travel and conveyance', 'expense', null, false],
        ['6900', 'Miscellaneous expenses', 'expense', null, false],
    ];

    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 12)->unique();
            $t->string('name');
            $t->string('type', 12);                 // asset|liability|equity|income|expense
            $t->string('subtype', 20)->nullable();  // cash|bank|receivable|payable|advances|tax|sales|returns|cost_of_sales|retained|opening
            $t->text('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->index('type');
        });

        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete(); // its own ledger account
            $t->string('name');
            $t->string('kind', 12)->default('bank'); // bank|cash|card|wallet
            $t->string('bank_name')->nullable();
            $t->string('last4', 8)->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('active')->default(true);
            $t->timestampsTz();
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->string('entry_no')->unique();                 // JE/26-27/0001
            $t->date('entry_date');
            $t->string('narration', 300);
            $t->string('source_type', 20)->default('manual'); // manual|opening|invoice|receipt|advance_apply|credit_note|reversal
            $t->unsignedBigInteger('source_id')->nullable();
            $t->unsignedBigInteger('reverses_id')->nullable(); // set on a reversal: the entry it undoes
            $t->timestampTz('reversed_at')->nullable();         // set on the original once it has been reversed
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('entry_date');
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->decimal('debit', 14, 2)->default(0);
            $t->decimal('credit', 14, 2)->default(0);
            $t->string('memo')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable()->index(); // party, for the customer ledger
            $t->unsignedBigInteger('deal_id')->nullable()->index();
            $t->index('account_id');
        });

        Schema::create('accounting_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('value')->nullable();
        });

        Schema::table('receipts', function (Blueprint $t) {
            $t->unsignedBigInteger('bank_account_id')->nullable()->index();
        });

        $now = now();
        foreach (self::CHART as [$code, $name, $type, $sub, $system]) {
            DB::table('accounts')->insert(['code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $sub, 'is_system' => $system, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Accountant role (editable on the Roles page) with the finance permissions and the sales documents it works on.
        if (! DB::table('roles')->where('slug', 'accountant')->exists()) {
            $id = DB::table('roles')->insertGetId(['name' => 'Accountant', 'slug' => 'accountant', 'description' => 'Keeps the books: chart of accounts, journal, banking, payments and invoices.', 'is_system' => false, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_id' => $id, 'permission' => $p], Permissions::defaults('accountant')));
        }
    }

    public function down(): void
    {
        Schema::table('receipts', fn (Blueprint $t) => $t->dropColumn('bank_account_id'));
        Schema::dropIfExists('accounting_settings');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('accounts');
        DB::table('roles')->where('slug', 'accountant')->delete();
    }
};

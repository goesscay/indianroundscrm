<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('invoice_no')->nullable()->unique();   // assigned when issued (no gaps in the series)
            $t->string('status')->default('draft');           // draft|issued|cancelled
            $t->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $t->string('title');
            $t->date('issue_date')->nullable();
            $t->string('destination')->nullable();
            $t->date('travel_date')->nullable();
            $t->unsignedInteger('adults')->default(1);
            $t->unsignedInteger('children')->default(0);
            // billing details frozen at issue time so later customer edits never change a tax document
            $t->string('bill_name')->nullable();
            $t->string('bill_gstin', 20)->nullable();
            $t->text('bill_address')->nullable();
            $t->string('place_of_supply')->nullable();        // customer state
            $t->string('sac_code', 12)->nullable();
            $t->string('gst_mode')->default('unspecified');   // cgst_sgst|igst|unspecified
            $t->double('tcs_rate')->default(0);
            $t->double('subtotal')->default(0);
            $t->double('discount_total')->default(0);
            $t->double('tax_total')->default(0);
            $t->double('cgst_total')->default(0);
            $t->double('sgst_total')->default(0);
            $t->double('igst_total')->default(0);
            $t->double('tcs_total')->default(0);
            $t->double('total')->default(0);
            $t->double('paid_total')->default(0);             // receipts less refunds
            $t->double('credited_total')->default(0);         // credit notes
            $t->text('notes')->nullable();
            $t->text('terms')->nullable();
            $t->timestampTz('issued_at')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('customer_id');
            $t->index('deal_id');
            $t->index('status');
        });

        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('kind')->default('other');
            $t->string('description');
            $t->double('qty')->default(1);
            $t->double('unit_price')->default(0);
            $t->double('discount')->default(0);
            $t->double('tax_rate')->default(0);
            $t->double('amount')->default(0);
            $t->double('tax')->default(0);
            $t->index('invoice_id');
        });

        Schema::create('invoice_installments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('label');
            $t->date('due_date');
            $t->double('amount');
            $t->index('invoice_id');
        });

        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->string('receipt_no')->unique();                // RCT/26-27/0001 (receipt) or RFD/26-27/0001 (refund)
            $t->string('kind')->default('receipt');            // receipt|refund
            $t->string('status')->default('valid');            // valid|cancelled
            $t->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete(); // null = advance not yet applied
            $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->double('amount');
            $t->date('received_date');
            $t->string('mode')->default('bank_transfer');      // upi|bank_transfer|card|cash|cheque|other
            $t->string('reference')->nullable();
            $t->string('deposited_to')->nullable();            // bank / cash account name (linked to the ledger in the accounts module)
            $t->text('notes')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('customer_id');
            $t->index('invoice_id');
            $t->index('received_date');
        });

        Schema::create('credit_notes', function (Blueprint $t) {
            $t->id();
            $t->string('credit_note_no')->unique();            // CN/26-27/0001
            $t->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $t->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $t->date('issue_date');
            $t->double('amount');                              // total credited, tax included
            $t->double('taxable_part')->default(0);
            $t->double('tax_part')->default(0);
            $t->string('reason');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('invoice_id');
        });

        Schema::create('invoice_emails', function (Blueprint $t) {
            $t->id();
            $t->string('doc_type', 12);                        // invoice|receipt
            $t->unsignedBigInteger('doc_id');
            $t->text('to_list');
            $t->string('subject');
            $t->string('status');
            $t->text('error')->nullable();
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->nullable();
            $t->index(['doc_type', 'doc_id']);
        });

        foreach (['sales', 'operations'] as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }
            $wanted = array_filter(Permissions::defaults($slug), fn ($p) => str_starts_with($p, 'invoices.') || str_starts_with($p, 'receipts.'));
            $have = DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission')->all();
            $rows = [];
            foreach (array_diff($wanted, $have) as $p) {
                $rows[] = ['role_id' => $roleId, 'permission' => $p];
            }
            if ($rows) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_emails');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('invoice_installments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};

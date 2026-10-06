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
        Schema::create('packages', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('destination')->nullable();
            $t->string('category')->default('domestic'); // domestic|international|honeymoon|group|pilgrimage|adventure|other
            $t->unsignedSmallInteger('nights')->default(0);
            $t->unsignedSmallInteger('days')->default(0);
            $t->text('summary')->nullable();
            $t->text('inclusions')->nullable();
            $t->text('exclusions')->nullable();
            $t->text('terms')->nullable();
            $t->double('adult_price')->default(0);   // per adult
            $t->double('child_price')->default(0);   // per child
            $t->double('adult_cost')->default(0);    // supplier cost per adult
            $t->double('child_cost')->default(0);
            $t->double('gst_rate')->default(5);
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index('destination');
            $t->index('active');
        });

        Schema::create('package_seasons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $t->string('name');
            $t->date('start_date');
            $t->date('end_date');
            $t->double('adult_price')->default(0);
            $t->double('child_price')->default(0);
            $t->double('adult_cost')->default(0);
            $t->double('child_cost')->default(0);
            $t->index('package_id');
        });

        Schema::create('package_days', function (Blueprint $t) {
            $t->id();
            $t->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $t->unsignedSmallInteger('day_no');
            $t->string('title');
            $t->text('description')->nullable();
            $t->index('package_id');
        });

        Schema::create('quotes', function (Blueprint $t) {
            $t->id();
            $t->string('quote_no');                 // IR-Q-26-0001, revisions IR-Q-26-0001-R2
            $t->unsignedBigInteger('root_id')->nullable()->index(); // first version of this quote (null = itself)
            $t->unsignedSmallInteger('version')->default(1);
            $t->string('status')->default('draft'); // draft|sent|accepted|rejected|superseded
            $t->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $t->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $t->string('title');
            $t->date('issue_date');
            $t->date('valid_until')->nullable();
            $t->string('destination')->nullable();
            $t->date('travel_date')->nullable();
            $t->unsignedInteger('duration_days')->nullable();
            $t->unsignedInteger('adults')->default(1);
            $t->unsignedInteger('children')->default(0);
            $t->double('tcs_rate')->default(0);
            $t->double('subtotal')->default(0);     // sum of qty * unit price
            $t->double('discount_total')->default(0);
            $t->double('tax_total')->default(0);
            $t->double('tcs_total')->default(0);
            $t->double('total')->default(0);
            $t->text('intro')->nullable();
            $t->text('inclusions')->nullable();
            $t->text('exclusions')->nullable();
            $t->text('terms')->nullable();
            $t->text('itinerary')->nullable();     // JSON [{day_no,title,description}]
            $t->string('reject_reason')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['quote_no']);
            $t->index('customer_id');
            $t->index('deal_id');
            $t->index('status');
        });

        Schema::create('quote_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $t->unsignedSmallInteger('position')->default(0);
            $t->string('kind')->default('other'); // package|hotel|flight|transfer|visa|insurance|activity|other
            $t->string('description');
            $t->double('qty')->default(1);
            $t->double('unit_price')->default(0);
            $t->double('discount')->default(0);    // absolute amount for the line
            $t->double('tax_rate')->default(0);
            $t->double('amount')->default(0);      // qty*unit - discount (taxable value)
            $t->double('tax')->default(0);
            $t->index('quote_id');
        });

        Schema::create('quote_emails', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $t->text('to_list');
            $t->string('subject');
            $t->string('status');                  // sent|simulated_sent|failed
            $t->text('error')->nullable();
            $t->boolean('with_pdf')->default(true);
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->nullable();
        });

        // default permissions for the existing system roles (idempotent)
        foreach (['sales', 'operations'] as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }
            $wanted = array_filter(Permissions::defaults($slug), fn ($p) => str_starts_with($p, 'packages.') || str_starts_with($p, 'quotes.'));
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
        Schema::dropIfExists('quote_emails');
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('package_days');
        Schema::dropIfExists('package_seasons');
        Schema::dropIfExists('packages');
    }
};

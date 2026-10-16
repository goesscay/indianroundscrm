<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceLine;
use App\Models\Quote;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Billing;
use App\Services\CustomerMail;
use App\Services\DocPdf;
use App\Services\Posting;
use App\Services\Reminders;
use App\Support\ActivityLogger;
use App\Support\LineMath;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    private const WITH = ['customer', 'deal:id,deal_no,name', 'owner:id,name'];

    // ------------------------------------------------------------------ helpers

    public static function scopeForUser($query, User $user)
    {
        if (! $user->hasPermission('invoices.view_all')) {
            $query->where(function ($w) use ($user) {
                $w->where('invoices.owner_id', $user->id)->orWhere('invoices.created_by', $user->id);
            });
        }

        return $query;
    }

    public static function find(string $id, User $user): Invoice
    {
        $i = ctype_digit($id) ? self::scopeForUser(Invoice::query()->with([...self::WITH, 'lines', 'installments', 'receipts', 'creditNotes']), $user)->where('invoices.id', (int) $id)->first() : null;
        if (! $i) {
            Fail::notFound('Invoice not found');
        }

        return $i;
    }

    private function date(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = is_scalar($v) ? substr((string) $v, 0, 10) : '';
        try {
            $c = Carbon::createFromFormat('!Y-m-d', $v);
            if ($c === false || $c->format('Y-m-d') !== $v) {
                throw new \Exception('bad');
            }
        } catch (\Throwable) {
            Fail::unprocessable([$field => 'Invalid date (use YYYY-MM-DD)']);
        }

        return $v;
    }

    private function txt(mixed $v, int $max = 20000): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function today(): string
    {
        return now('Asia/Kolkata')->toDateString();
    }

    private function validated(array $b, bool $partial): array
    {
        return Fail::validate($b, [
            'customer_id' => ($partial ? 'sometimes|' : 'required|').'nullable',
            'deal_id' => 'nullable', 'quote_id' => 'nullable',
            'title' => ($partial ? 'sometimes|required' : 'nullable').'|string|max:200',
            'issue_date' => 'nullable|string', 'destination' => 'nullable|string|max:200', 'travel_date' => 'nullable|string',
            'adults' => 'nullable|integer|min:0|max:500', 'children' => 'nullable|integer|min:0|max:500',
            'tcs_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string', 'terms' => 'nullable|string',
            'lines' => 'sometimes|array|max:100',
            'installments' => 'sometimes|nullable|array|max:12',
            'owner_id' => 'nullable',
        ]);
    }

    private function resolveCustomer(mixed $v, User $user): Customer
    {
        $c = is_scalar($v) && ctype_digit((string) $v)
            ? CustomerController::scopeForUser(Customer::query(), $user)->where('customers.id', (int) $v)->first() : null;
        if (! $c) {
            Fail::unprocessable(['customer_id' => 'Customer not found']);
        }

        return $c;
    }

    private function resolveDeal(mixed $v, Customer $c, User $user): ?Deal
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = is_scalar($v) && ctype_digit((string) $v)
            ? DealController::scopeForUser(Deal::query(), $user)->where('deals.id', (int) $v)->first() : null;
        if (! $d || (int) $d->customer_id !== (int) $c->id) {
            Fail::unprocessable(['deal_id' => 'Deal not found for this customer']);
        }

        return $d;
    }

    private function resolveQuote(mixed $v, Customer $c, User $user): ?Quote
    {
        if ($v === null || $v === '') {
            return null;
        }
        $q = is_scalar($v) && ctype_digit((string) $v)
            ? QuoteController::scopeForUser(Quote::query()->with('lines'), $user)->where('quotes.id', (int) $v)->first() : null;
        if (! $q || (int) $q->customer_id !== (int) $c->id || $q->status === 'superseded') {
            Fail::unprocessable(['quote_id' => 'Quote not found for this customer']);
        }

        return $q;
    }

    private function billAddress(Customer $c): ?string
    {
        $a = collect([$c->address_line, $c->city, $c->state, $c->pincode, $c->country])->filter()->implode(', ');

        return $a === '' ? null : $a;
    }

    private function snapshot(Invoice $inv, Customer $c): void
    {
        $inv->bill_name = $c->company ? $c->name.' ('.$c->company.')' : $c->name;
        $inv->bill_gstin = $c->gstin;
        $inv->bill_address = $this->billAddress($c);
        $inv->place_of_supply = $c->state;
        $inv->sac_code = config('crm.company.sac') ?: null;
    }

    /** Default payment plan: 50% advance now, 50% balance N days before travel (or one payment if there is no travel date). */
    private function defaultSchedule(Invoice $inv): array
    {
        $total = round((float) $inv->total, 2);
        $issue = $inv->issue_date?->format('Y-m-d') ?? $this->today();
        if ($total <= 0) {
            return [];
        }
        $travel = $inv->travel_date?->format('Y-m-d');
        if ($travel) {
            $balanceDue = Carbon::parse($travel)->subDays((int) config('crm.balance_days_before_travel', 15))->toDateString();
            if ($balanceDue > $issue) {
                $adv = round($total / 2, 2);

                return [['label' => 'Advance', 'due_date' => $issue, 'amount' => $adv], ['label' => 'Balance', 'due_date' => $balanceDue, 'amount' => round($total - $adv, 2)]];
            }
        }

        return [['label' => 'Full payment', 'due_date' => Carbon::parse($issue)->addDays((int) config('crm.invoice_due_days', 7))->toDateString(), 'amount' => $total]];
    }

    private function cleanSchedule(mixed $rows, float $total): array
    {
        $out = [];
        foreach ((array) $rows as $i => $r) {
            if (! is_array($r)) {
                continue;
            }
            $amount = round((float) ($r['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $out[] = ['label' => mb_substr(trim((string) ($r['label'] ?? '')) ?: 'Payment '.(count($out) + 1), 0, 80),
                'due_date' => $this->date($r['due_date'] ?? null, "installments.$i.due_date") ?? $this->today(), 'amount' => $amount];
        }
        usort($out, fn ($a, $b) => strcmp($a['due_date'], $b['due_date']));
        $sum = round(array_sum(array_column($out, 'amount')), 2);
        if ($out && abs($sum - $total) > 0.01) {
            Fail::unprocessable(['installments' => sprintf('The payments add up to ₹%s but the invoice total is ₹%s.', number_format($sum, 2), number_format($total, 2))]);
        }

        return $out;
    }

    /** Persist lines, totals (with GST split) and the payment schedule. $schedule null = rebuild the default plan. */
    private function writeBody(Invoice $inv, array $lines, float $tcsRate, ?array $schedule): void
    {
        $mode = LineMath::gstMode(config('crm.company.state'), $inv->place_of_supply);
        $t = LineMath::totals($lines, $tcsRate, $mode);
        $inv->lines()->delete();
        foreach ($lines as $i => $l) {
            unset($l['gross']);
            InvoiceLine::create($l + ['invoice_id' => $inv->id, 'position' => $i]);
        }
        $inv->forceFill($t + ['tcs_rate' => $tcsRate, 'gst_mode' => $mode])->save();

        $rows = $schedule === null ? $this->defaultSchedule($inv) : $this->cleanSchedule($schedule, $inv->total);
        $inv->installments()->delete();
        foreach ($rows as $i => $r) {
            InvoiceInstallment::create($r + ['invoice_id' => $inv->id, 'position' => $i]);
        }
    }

    private function fill(Invoice $inv, array $d): void
    {
        foreach (['title'] as $f) {
            if (array_key_exists($f, $d) && $d[$f] !== null) {
                $inv->{$f} = trim((string) $d[$f]);
            }
        }
        if (array_key_exists('destination', $d)) {
            $inv->destination = $this->txt($d['destination'], 200);
        }
        foreach (['notes', 'terms'] as $f) {
            if (array_key_exists($f, $d)) {
                $inv->{$f} = $this->txt($d[$f]);
            }
        }
        if (array_key_exists('travel_date', $d)) {
            $inv->travel_date = $this->date($d['travel_date'], 'travel_date');
        }
        if (array_key_exists('issue_date', $d)) {
            $inv->issue_date = $this->date($d['issue_date'], 'issue_date');
        }
        foreach (['adults' => 1, 'children' => 0] as $f => $def) {
            if (array_key_exists($f, $d)) {
                $inv->{$f} = $d[$f] === null ? $def : (int) $d[$f];
            }
        }
    }

    private function snap(Invoice $i): array
    {
        return ['invoice_no' => $i->invoice_no, 'status' => $i->status, 'total' => $i->total, 'paid' => $i->paid_total, 'customer_id' => $i->customer_id];
    }

    private function pdfData(Invoice $inv): array
    {
        $inv->loadMissing(['customer', 'lines', 'installments', 'receipts', 'creditNotes']);

        return ['inv' => $inv, 'schedule' => $inv->scheduleStatus()];
    }

    private function ensureIssued(Invoice $inv): void
    {
        if ($inv->status !== 'issued') {
            Fail::http(409, 'Only issued invoices can take payments, credit notes or refunds.');
        }
    }

    // ------------------------------------------------------------------ routes

    public function index(Request $request)
    {
        $user = $request->user();
        $q = self::scopeForUser(Invoice::query()->with(self::WITH), $user);

        $status = (string) $request->query('status', '');
        $today = now('Asia/Kolkata')->toDateString();
        if ($status === 'draft' || $status === 'cancelled') {
            $q->where('invoices.status', $status);
        } elseif ($status === 'paid') {
            $q->where('invoices.status', 'issued')->whereRaw('invoices.total - invoices.credited_total - invoices.paid_total <= 0.005');
        } elseif ($status === 'unpaid') { // issued with money outstanding
            $q->where('invoices.status', 'issued')->whereRaw('invoices.total - invoices.credited_total - invoices.paid_total > 0.005');
        } elseif ($status === 'overdue') { // computed with the same first-in-first-out rule the invoice page uses
            $ids = self::scopeForUser(Invoice::query()->with('installments'), $user)->where('invoices.status', 'issued')
                ->whereRaw('invoices.total - invoices.credited_total - invoices.paid_total > 0.005')->get()
                ->filter(fn (Invoice $i) => $i->paymentStatus() === 'overdue')->pluck('id');
            $q->whereIn('invoices.id', $ids);
        }
        foreach (['customer_id', 'deal_id'] as $f) {
            if (($v = (string) $request->query($f, '')) !== '') {
                $q->where("invoices.$f", ctype_digit($v) ? (int) $v : -1);
            }
        }
        $owner = (string) $request->query('owner', 'all');
        if ($owner === 'me') {
            $q->where('invoices.owner_id', $user->id);
        } elseif ($owner !== '' && $owner !== 'all') {
            $q->where('invoices.owner_id', ctype_digit($owner) ? (int) $owner : -1);
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(function ($w) use ($like, $op) {
                $w->where('invoices.invoice_no', $op, $like)->orWhere('invoices.title', $op, $like)
                    ->orWhereIn('invoices.customer_id', Customer::query()->select('id')->where(fn ($c) => $c->where('name', $op, $like)->orWhere('phone', $op, $like)));
            });
        }
        $q->orderByDesc('invoices.id');

        if (Paging::requested($request)) {
            $res = Paging::paginate($request, $q, fn (Invoice $i) => $i->toApi());
            $base = self::scopeForUser(Invoice::query(), $user)->where('invoices.status', 'issued');
            $res['outstanding'] = round((float) (clone $base)->selectRaw('COALESCE(SUM(invoices.total - invoices.credited_total - invoices.paid_total), 0) as o')->whereRaw('invoices.total - invoices.credited_total - invoices.paid_total > 0')->value('o'), 2);

            return $res;
        }
        $limit = max(1, (int) $request->query('limit', 500));

        return $q->limit($limit)->get()->map(fn (Invoice $i) => $i->toApi())->values();
    }

    public function show(Request $request, string $id)
    {
        $inv = self::find($id, $request->user());
        $a = $inv->toApi(true);
        $a['overdue_days'] = Reminders::daysOverdue($inv);
        $a['reminders'] = DB::table('invoice_emails')->leftJoin('users', 'users.id', '=', 'invoice_emails.sent_by')
            ->where('doc_type', 'invoice')->where('doc_id', $inv->id)->where('kind', 'reminder')->orderByDesc('invoice_emails.id')
            ->get(['invoice_emails.created_at', 'invoice_emails.to_list', 'invoice_emails.status', 'invoice_emails.trigger', 'users.name as sent_by'])
            ->map(fn ($r) => (array) $r)->all();

        return $a;
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $d = $this->validated(Fail::body($request), false);
        $customer = $this->resolveCustomer($d['customer_id'], $user);
        $quote = $this->resolveQuote($d['quote_id'] ?? null, $customer, $user);
        $deal = $this->resolveDeal($d['deal_id'] ?? ($quote?->deal_id), $customer, $user);

        $inv = DB::transaction(function () use ($d, $customer, $quote, $deal, $user) {
            $inv = new Invoice([
                'status' => 'draft', 'customer_id' => $customer->id, 'deal_id' => $deal?->id, 'quote_id' => $quote?->id, 'created_by' => $user->id,
                'owner_id' => ($deal?->owner_id) ?: ($customer->owner_id ?: $user->id),
                'issue_date' => $this->today(),
                'title' => $quote?->title ?? ($deal?->name ?? 'Invoice'), 'destination' => $quote?->destination ?? $deal?->destination,
                'travel_date' => ($quote?->travel_date ?? $deal?->travel_date)?->format('Y-m-d'),
                'adults' => $quote?->adults ?? $deal?->adults ?? 1, 'children' => $quote?->children ?? $deal?->children ?? 0,
                'terms' => $quote?->terms,
            ]);
            $this->fill($inv, $d);
            if (array_key_exists('owner_id', $d) && ctype_digit((string) $d['owner_id']) && User::where('id', (int) $d['owner_id'])->where('active', true)->exists()) {
                $inv->owner_id = (int) $d['owner_id'];
            }
            $this->snapshot($inv, $customer);
            $inv->save();
            $rows = array_key_exists('lines', $d) ? $d['lines'] : ($quote ? $quote->lines->map(fn ($l) => $l->toArray())->all() : []);
            $tcs = (float) ($d['tcs_rate'] ?? $quote?->tcs_rate ?? 0);
            $this->writeBody($inv, LineMath::clean($rows), $tcs, array_key_exists('installments', $d) && $d['installments'] !== null ? $d['installments'] : null);

            return $inv;
        });
        ActivityLogger::log($user, 'create_invoice', 'invoice', $inv->id, null, $this->snap($inv));

        return self::find((string) $inv->id, $user)->toApi(true);
    }

    /** POST /quotes/{id}/invoice: a draft invoice with the quote's lines and trip details. */
    public function fromQuote(Request $request, string $id)
    {
        $user = $request->user();
        $quote = ctype_digit($id) ? QuoteController::scopeForUser(Quote::query(), $user)->where('quotes.id', (int) $id)->first() : null;
        if (! $quote) {
            Fail::notFound('Quote not found');
        }
        if ($quote->status === 'superseded') {
            Fail::http(409, 'This quote has been replaced by a newer version.');
        }
        $existing = Invoice::where('quote_id', $quote->id)->whereIn('status', ['draft', 'issued'])->first();
        if ($existing) {
            Fail::http(409, 'This quote already has an invoice: '.($existing->invoice_no ?: 'a draft').'.');
        }
        $fake = Request::create('/', 'POST', [], [], [], [], json_encode(['customer_id' => (string) $quote->customer_id, 'quote_id' => (string) $quote->id, 'deal_id' => $quote->deal_id ? (string) $quote->deal_id : null]));
        $fake->setUserResolver(fn () => $user);

        return $this->store($fake);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $inv = self::find($id, $user);
        if ($inv->status !== 'draft') {
            Fail::http(409, 'Only draft invoices can be edited. Issued invoices are corrected with a credit note.');
        }
        $d = $this->validated(Fail::body($request), true);
        $before = $this->snap($inv);

        DB::transaction(function () use ($inv, $d, $user) {
            if (array_key_exists('customer_id', $d) && (string) $d['customer_id'] !== (string) $inv->customer_id) {
                $inv->customer_id = $this->resolveCustomer($d['customer_id'], $user)->id;
                $inv->deal_id = null;
                $inv->quote_id = null;
            }
            $customer = Customer::findOrFail($inv->customer_id);
            if (array_key_exists('deal_id', $d)) {
                $inv->deal_id = $this->resolveDeal($d['deal_id'], $customer, $user)?->id;
            }
            if (array_key_exists('owner_id', $d) && ctype_digit((string) $d['owner_id']) && User::where('id', (int) $d['owner_id'])->where('active', true)->exists()) {
                $inv->owner_id = (int) $d['owner_id'];
            }
            $this->fill($inv, $d);
            $this->snapshot($inv, $customer);
            $inv->save();
            $rows = array_key_exists('lines', $d) ? $d['lines'] : $inv->lines->map(fn ($l) => $l->toArray())->all();
            $this->writeBody($inv, LineMath::clean($rows), (float) ($d['tcs_rate'] ?? $inv->tcs_rate),
                array_key_exists('installments', $d) && $d['installments'] !== null ? $d['installments'] : null);
        });
        ActivityLogger::log($user, 'update_invoice', 'invoice', $inv->id, $before, $this->snap($inv->fresh()));

        return self::find($id, $user)->toApi(true);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $inv = self::find($id, $user);
        if ($inv->status !== 'draft') {
            Fail::http(409, 'Only draft invoices can be deleted. Cancel or credit an issued invoice instead.');
        }
        $inv->delete();
        ActivityLogger::log($user, 'delete_invoice', 'invoice', $id, $this->snap($inv), null);

        return ['ok' => true];
    }

    /** Draft -> issued: freezes the billing details, assigns the next number in the financial-year series. */
    public function issue(Request $request, string $id)
    {
        $user = $request->user();
        $inv = self::find($id, $user);
        if ($inv->status !== 'draft') {
            Fail::http(409, 'This invoice has already been issued.');
        }
        if ($inv->lines->isEmpty() || $inv->total <= 0) {
            Fail::unprocessable(['lines' => 'Add at least one price line before issuing.']);
        }
        DB::transaction(function () use ($inv, $user) {
            $customer = Customer::findOrFail($inv->customer_id);
            $this->snapshot($inv, $customer);
            $inv->issue_date = $inv->issue_date ?: now('Asia/Kolkata')->startOfDay();
            $inv->save();
            $this->writeBody($inv, LineMath::clean($inv->lines->map(fn ($l) => $l->toArray())->all()), (float) $inv->tcs_rate,
                $inv->installments->map(fn ($i) => ['label' => $i->label, 'due_date' => $i->due_date->format('Y-m-d'), 'amount' => $i->amount])->all() ?: null);
            $inv->invoice_no = Billing::nextNumber('INV', $inv->issue_date);
            $inv->status = 'issued';
            $inv->issued_at = now();
            $inv->save();
            Posting::invoiceIssued($inv->fresh(), $user); // books the sale; refused when the date is in a closed period
        });
        ActivityLogger::log($user, 'issue_invoice', 'invoice', $inv->id, null, $this->snap($inv->fresh()));
        // advances the customer already paid go onto the new invoice, so its balance is right from the start
        $advances = Billing::applyAdvancesAtIssue($inv->fresh(), $user);

        return array_merge(self::find($id, $user)->toApi(true), ['advances_applied' => $advances]);
    }

    public function cancel(Request $request, string $id)
    {
        $user = $request->user();
        $inv = self::find($id, $user);
        $d = Fail::validate(Fail::body($request), ['reason' => 'required|string|max:300']);
        if ($inv->status !== 'issued') {
            Fail::http(409, 'Only issued invoices can be cancelled.');
        }
        if (Receipt::where('invoice_id', $inv->id)->where('status', 'valid')->exists() || $inv->creditNotes->isNotEmpty()) {
            Fail::http(409, 'This invoice has payments or credit notes. Issue a credit note for the full amount instead.');
        }
        $before = $this->snap($inv);
        DB::transaction(function () use ($inv, $d, $user) {
            $inv->status = 'cancelled';
            $inv->cancelled_at = now();
            $inv->cancel_reason = trim($d['reason']);
            $inv->save();
            Posting::invoiceCancelled($inv, $user);
        });
        ActivityLogger::log($user, 'cancel_invoice', 'invoice', $inv->id, $before, $this->snap($inv));

        return self::find($id, $user)->toApi(true);
    }

    public function pdf(Request $request, string $id)
    {
        $inv = self::find($id, $request->user());
        if ($inv->status === 'draft') {
            Fail::http(409, 'Issue the invoice first. A draft has no invoice number yet.');
        }

        return response(DocPdf::render('pdf.invoice', $this->pdfData($inv)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.DocPdf::filename($inv->invoice_no).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function emailPreview(Request $request, string $id)
    {
        $inv = self::find($id, $request->user());
        if ($request->boolean('reminder')) {
            Reminders::assertRemindable($inv);

            return ['to' => array_values(array_filter([$inv->customer->email])), 'filename' => DocPdf::filename((string) $inv->invoice_no)] + Reminders::compose($inv);
        }
        $co = config('crm.company.name');
        $due = collect($inv->scheduleStatus())->first(fn ($s) => $s['status'] !== 'paid')['due_date'] ?? null;

        return [
            'to' => array_values(array_filter([$inv->customer->email])),
            'subject' => "Invoice {$inv->invoice_no} — {$inv->title}",
            'message' => "Dear {$inv->customer->name},\n\nPlease find attached invoice {$inv->invoice_no} for {$inv->title}.\n\n".
                'Amount due: ₹'.number_format($inv->balance(), 2).($due ? ' (next payment due '.Carbon::parse($due)->format('d M Y').')' : '').
                ".\n\nThank you for travelling with $co.\n\nWarm regards,\n$co",
            'filename' => DocPdf::filename((string) $inv->invoice_no),
        ];
    }

    public function send(Request $request, string $id)
    {
        $user = $request->user();
        $inv = self::find($id, $user);
        if ($inv->status !== 'issued') {
            Fail::http(409, 'Only issued invoices can be e-mailed.');
        }
        $d = Fail::validate(Fail::body($request), [
            'to' => 'required|array|min:1|max:10', 'to.*' => 'required|email|max:200', 'cc' => 'nullable|array|max:10', 'cc.*' => 'email|max:200',
            'subject' => 'required|string|max:300', 'message' => 'required|string|max:10000', 'attach_pdf' => 'sometimes|boolean', 'reminder' => 'sometimes|boolean',
        ]);
        if (! empty($d['reminder'])) {
            Reminders::assertRemindable($inv);
            $res = Reminders::send($inv, $d['to'], $d['cc'] ?? [], $d['subject'], $d['message'], ! array_key_exists('attach_pdf', $d) || $d['attach_pdf'], $user->id, 'manual');
            $failed = ($res['status'] ?? '') === 'failed';
            ActivityLogger::log($user, $failed ? 'invoice_reminder_failed' : 'invoice_reminder_sent', 'invoice', $inv->id, null, ['to' => $d['to'], 'status' => $res['status']]);
            if ($failed) {
                Fail::http(502, 'The e-mail could not be sent: '.($res['error'] ?? 'unknown error'));
            }

            return ['status' => $res['status'], 'note' => $res['note'] ?? null];
        }
        $pdf = (! array_key_exists('attach_pdf', $d) || $d['attach_pdf'])
            ? ['name' => DocPdf::filename($inv->invoice_no), 'type' => 'application/pdf', 'bytes' => DocPdf::render('pdf.invoice', $this->pdfData($inv))] : null;
        $rows = $inv->lines->map(fn ($l) => [$l->description, $l->amount + $l->tax])->all();
        $res = CustomerMail::send($d['to'], $d['cc'] ?? [], $d['subject'], $d['message'], "{$inv->title} · {$inv->invoice_no}", $rows,
            'Total (incl. taxes)', $inv->total, $pdf);
        $failed = ($res['status'] ?? '') === 'failed';
        DB::table('invoice_emails')->insert(['doc_type' => 'invoice', 'doc_id' => $inv->id, 'to_list' => implode(', ', $d['to']), 'subject' => $d['subject'],
            'status' => $res['status'], 'error' => $res['error'] ?? null, 'sent_by' => $user->id, 'created_at' => now(),
            'internet_message_id' => $res['internet_message_id'] ?? null, 'conversation_id' => $res['conversation_id'] ?? null]);
        ActivityLogger::log($user, $failed ? 'invoice_email_failed' : 'invoice_email_sent', 'invoice', $inv->id, null, ['to' => $d['to'], 'status' => $res['status']]);
        if ($failed) {
            Fail::http(502, 'The e-mail could not be sent: '.($res['error'] ?? 'unknown error'));
        }

        return ['status' => $res['status'], 'note' => $res['note'] ?? null];
    }

    // ------------------------------------------------------------------ money

    private function moneyBody(Request $request, bool $needsMode = true): array
    {
        return Fail::validate(Fail::body($request), [
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'received_date' => 'nullable|string', 'mode' => ($needsMode ? 'nullable|' : 'nullable|').'in:'.implode(',', Receipt::MODES),
            'reference' => 'nullable|string|max:120', 'deposited_to' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:1000',
            'bank_account_id' => 'nullable',
        ]);
    }

    public function addReceipt(Request $request, string $id)
    {
        $user = $request->user();
        $inv = self::find($id, $user);
        $this->ensureIssued($inv);
        $d = $this->moneyBody($request);
        $d['received_date'] = $this->date($d['received_date'] ?? null, 'received_date') ?? $this->today();
        if ($d['received_date'] > $this->today()) {
            Fail::unprocessable(['received_date' => 'The payment date cannot be in the future']);
        }
        if ($d['amount'] > $inv->balance() + 0.005) {
            Fail::unprocessable(['amount' => sprintf('This is more than the balance of ₹%s. Record the extra as an advance on the customer.', number_format($inv->balance(), 2))]);
        }
        Billing::refuseDuplicate((int) $inv->customer_id, $d, ! empty(Fail::body($request)['confirm_duplicate']));
        $r = DB::transaction(fn () => Billing::record($user, $inv->customer_id, $inv, 'receipt', $d));

        return ['receipt' => $r->toApi(), 'invoice' => self::find($id, $user)->toApi(true)];
    }

    public function addCreditNote(Request $request, string $id)
    {
        $user = $request->user();
        $inv = self::find($id, $user);
        $this->ensureIssued($inv);
        $d = Fail::validate(Fail::body($request), ['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:300', 'issue_date' => 'nullable|string']);
        $room = round($inv->total - $inv->credited_total, 2);
        if ($d['amount'] > $room + 0.005) {
            Fail::unprocessable(['amount' => sprintf('A credit note cannot be more than ₹%s, what is left of the invoice.', number_format($room, 2))]);
        }
        $date = $this->date($d['issue_date'] ?? null, 'issue_date') ?? $this->today();
        DB::transaction(function () use ($inv, $d, $date, $user) {
            $amount = round((float) $d['amount'], 2);
            $tax = $inv->total > 0 ? round($amount * $inv->tax_total / $inv->total, 2) : 0.0;
            $cn = CreditNote::create([
                'credit_note_no' => Billing::nextNumber('CN', Carbon::parse($date, 'Asia/Kolkata')), 'invoice_id' => $inv->id, 'customer_id' => $inv->customer_id,
                'issue_date' => $date, 'amount' => $amount, 'tax_part' => $tax, 'taxable_part' => round($amount - $tax, 2), 'reason' => trim($d['reason']), 'created_by' => $user->id,
            ]);
            Billing::settle($inv);
            Posting::creditNote($cn, $inv->fresh(), $user);
            ActivityLogger::log($user, 'issue_credit_note', 'invoice', $inv->id, null, ['credit_note_no' => $cn->credit_note_no, 'amount' => $amount]);
        });

        return self::find($id, $user)->toApi(true);
    }

    public function addRefund(Request $request, string $id)
    {
        $user = $request->user();
        $inv = self::find($id, $user);
        $this->ensureIssued($inv);
        $d = $this->moneyBody($request);
        $d['received_date'] = $this->date($d['received_date'] ?? null, 'received_date') ?? $this->today();
        if ($d['amount'] > $inv->refundable() + 0.005) {
            Fail::unprocessable(['amount' => sprintf('You can refund at most ₹%s: the money received beyond what is now owed.', number_format($inv->refundable(), 2))]);
        }
        $r = DB::transaction(fn () => Billing::record($user, $inv->customer_id, $inv, 'refund', $d));

        return ['receipt' => $r->toApi(), 'invoice' => self::find($id, $user)->toApi(true)];
    }
}

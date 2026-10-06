<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Crm\MailSender;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Quote;
use App\Models\QuoteEmail;
use App\Models\QuoteLine;
use App\Models\User;
use App\Services\QuotePdf;
use App\Support\ActivityLogger;
use App\Support\Numbers;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class QuoteController extends Controller
{
    private const WITH = ['customer', 'deal:id,deal_no,name', 'owner:id,name'];

    // ------------------------------------------------------------------ helpers

    public static function scopeForUser($query, User $user)
    {
        if (! $user->hasPermission('quotes.view_all')) {
            $query->where(function ($w) use ($user) {
                $w->where('quotes.owner_id', $user->id)->orWhere('quotes.created_by', $user->id);
            });
        }

        return $query;
    }

    private function find(string $id, User $user): Quote
    {
        $q = ctype_digit($id) ? self::scopeForUser(Quote::query()->with([...self::WITH, 'lines', 'emails']), $user)->where('quotes.id', (int) $id)->first() : null;
        if (! $q) {
            Fail::notFound('Quote not found');
        }

        return $q;
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

    private function validated(array $b, bool $partial): array
    {
        $req = $partial ? 'sometimes|' : '';

        return Fail::validate($b, [
            'customer_id' => ($partial ? 'sometimes|' : 'required|').'nullable',
            'deal_id' => 'nullable', 'package_id' => 'nullable',
            'title' => ($partial ? 'sometimes|required' : 'required').'|string|max:200',
            'issue_date' => 'nullable|string', 'valid_until' => 'nullable|string',
            'destination' => 'nullable|string|max:200', 'travel_date' => 'nullable|string',
            'duration_days' => 'nullable|integer|min:0|max:365',
            'adults' => 'nullable|integer|min:0|max:500', 'children' => 'nullable|integer|min:0|max:500',
            'tcs_rate' => 'nullable|numeric|min:0|max:100',
            'intro' => 'nullable|string', 'inclusions' => 'nullable|string', 'exclusions' => 'nullable|string', 'terms' => 'nullable|string',
            'itinerary' => 'sometimes|nullable|array|max:60',
            'lines' => $req.'array|max:100',
            'owner_id' => 'nullable',
        ]);
    }

    /** Line maths: amount = qty*unit - discount (never below 0), tax = amount * rate. Totals rounded to paise. */
    private function cleanLines(mixed $rows): array
    {
        $out = [];
        foreach ((array) $rows as $i => $r) {
            $desc = is_array($r) ? trim((string) ($r['description'] ?? '')) : '';
            if ($desc === '') {
                continue;
            }
            $qty = max(0, (float) ($r['qty'] ?? 1));
            $unit = max(0, (float) ($r['unit_price'] ?? 0));
            $disc = max(0, (float) ($r['discount'] ?? 0));
            $rate = min(100, max(0, (float) ($r['tax_rate'] ?? 0)));
            $gross = round($qty * $unit, 2);
            if ($disc > $gross) {
                Fail::unprocessable(["lines.$i.discount" => 'Discount is more than the line amount']);
            }
            $amount = round($gross - $disc, 2);
            $kind = in_array($r['kind'] ?? '', QuoteLine::KINDS, true) ? $r['kind'] : 'other';
            $out[] = ['kind' => $kind, 'description' => mb_substr($desc, 0, 300), 'qty' => $qty, 'unit_price' => $unit, 'discount' => $disc,
                'tax_rate' => $rate, 'amount' => $amount, 'tax' => round($amount * $rate / 100, 2), 'gross' => $gross];
        }

        return $out;
    }

    private function writeLines(Quote $q, array $lines, float $tcsRate): void
    {
        $q->lines()->delete();
        $sub = $disc = $tax = 0.0;
        foreach ($lines as $i => $l) {
            $sub += $l['gross']; $disc += $l['discount']; $tax += $l['tax'];
            unset($l['gross']);
            QuoteLine::create($l + ['quote_id' => $q->id, 'position' => $i]);
        }
        $taxable = $sub - $disc;
        $tcs = round(($taxable + $tax) * $tcsRate / 100, 2);
        $q->forceFill([
            'subtotal' => round($sub, 2), 'discount_total' => round($disc, 2), 'tax_total' => round($tax, 2),
            'tcs_rate' => $tcsRate, 'tcs_total' => $tcs, 'total' => round($taxable + $tax + $tcs, 2),
        ])->save();
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

    private function resolvePackage(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_scalar($v) || ! ctype_digit((string) $v) || ! \App\Models\TravelPackage::where('id', (int) $v)->exists()) {
            Fail::unprocessable(['package_id' => 'Package not found']);
        }

        return (int) $v;
    }

    private function cleanItinerary(mixed $rows): ?string
    {
        $out = [];
        foreach ((array) $rows as $d) {
            $title = is_array($d) ? trim((string) ($d['title'] ?? '')) : '';
            if ($title !== '') {
                $out[] = ['day_no' => count($out) + 1, 'title' => mb_substr($title, 0, 200), 'description' => $this->txt($d['description'] ?? null, 4000)];
            }
        }

        return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : null;
    }

    private static function fy2(): string
    {
        return str_pad((string) (ForecastController::fyOf(Carbon::now('Asia/Kolkata')) % 100), 2, '0', STR_PAD_LEFT);
    }

    private function fill(Quote $q, array $d): void
    {
        foreach (['title'] as $f) {
            if (array_key_exists($f, $d)) {
                $q->{$f} = trim((string) $d[$f]);
            }
        }
        foreach (['destination'] as $f) {
            if (array_key_exists($f, $d)) {
                $q->{$f} = $this->txt($d[$f], 200);
            }
        }
        foreach (['intro', 'inclusions', 'exclusions', 'terms'] as $f) {
            if (array_key_exists($f, $d)) {
                $q->{$f} = $this->txt($d[$f]);
            }
        }
        foreach (['duration_days'] as $f) {
            if (array_key_exists($f, $d)) {
                $q->{$f} = $d[$f] === null ? null : (int) $d[$f];
            }
        }
        foreach (['adults' => 1, 'children' => 0] as $f => $def) {
            if (array_key_exists($f, $d)) {
                $q->{$f} = $d[$f] === null ? $def : (int) $d[$f];
            }
        }
        if (array_key_exists('travel_date', $d)) {
            $q->travel_date = $this->date($d['travel_date'], 'travel_date');
        }
        if (array_key_exists('issue_date', $d)) {
            $q->issue_date = $this->date($d['issue_date'], 'issue_date') ?? $q->issue_date ?? now('Asia/Kolkata')->toDateString();
        }
        if (array_key_exists('valid_until', $d)) {
            $q->valid_until = $this->date($d['valid_until'], 'valid_until');
        }
        if (array_key_exists('package_id', $d)) {
            $q->package_id = $this->resolvePackage($d['package_id']);
        }
        if (array_key_exists('itinerary', $d)) {
            $q->itinerary = $this->cleanItinerary($d['itinerary']);
        }
    }

    private function snapshot(Quote $q): array
    {
        return ['quote_no' => $q->quote_no, 'status' => $q->status, 'total' => $q->total, 'customer_id' => $q->customer_id, 'deal_id' => $q->deal_id];
    }

    /** What happens to the deal when a quote moves on. */
    private function syncDeal(Quote $q, string $event, User $user): void
    {
        $deal = $q->deal_id ? Deal::find($q->deal_id) : null;
        if (! $deal) {
            return;
        }
        $before = ['stage' => $deal->stage, 'selling_amount' => $deal->selling_amount];
        if ($event === 'sent' && $deal->stage === 'enquiry') {
            $deal->stage = 'quoted';
            $deal->probability = Deal::PROBABILITY['quoted'];
        }
        if ($event === 'accepted') {
            $deal->selling_amount = $q->total;
        }
        if ($deal->isDirty()) {
            $deal->save();
            ActivityLogger::log($user, 'update_deal', 'deal', $deal->id, $before, ['stage' => $deal->stage, 'selling_amount' => $deal->selling_amount, 'via_quote' => $q->quote_no]);
        }
    }

    // ------------------------------------------------------------------ routes

    public function index(Request $request)
    {
        $user = $request->user();
        $q = self::scopeForUser(Quote::query()->with(self::WITH), $user);

        $status = (string) $request->query('status', '');
        if ($status === 'expired') {
            $q->where('quotes.status', 'sent')->whereNotNull('quotes.valid_until')->where('quotes.valid_until', '<', now()->toDateString());
        } elseif ($status === 'sent') {
            $q->where('quotes.status', 'sent')->where(fn ($w) => $w->whereNull('quotes.valid_until')->orWhere('quotes.valid_until', '>=', now()->toDateString()));
        } elseif ($status !== '' && in_array($status, Quote::STATUSES, true)) {
            $q->where('quotes.status', $status);
        } else {
            $q->where('quotes.status', '!=', 'superseded'); // old versions only show on request
        }
        foreach (['customer_id', 'deal_id'] as $f) {
            if (($v = (string) $request->query($f, '')) !== '') {
                $q->where("quotes.$f", ctype_digit($v) ? (int) $v : -1);
            }
        }
        $owner = (string) $request->query('owner', 'all');
        if ($owner === 'me') {
            $q->where('quotes.owner_id', $user->id);
        } elseif ($owner !== '' && $owner !== 'all') {
            $q->where('quotes.owner_id', ctype_digit($owner) ? (int) $owner : -1);
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(function ($w) use ($like, $op) {
                $w->where('quotes.quote_no', $op, $like)->orWhere('quotes.title', $op, $like)->orWhere('quotes.destination', $op, $like)
                    ->orWhereIn('quotes.customer_id', Customer::query()->select('id')->where(fn ($c) => $c->where('name', $op, $like)->orWhere('phone', $op, $like)));
            });
        }
        $q->orderByDesc('quotes.id');

        if (Paging::requested($request)) {
            return Paging::paginate($request, $q, fn (Quote $x) => $x->toApi());
        }
        $limit = max(1, (int) $request->query('limit', 500));

        return $q->limit($limit)->get()->map(fn (Quote $x) => $x->toApi())->values();
    }

    public function show(Request $request, string $id)
    {
        return $this->find($id, $request->user())->toApi(true);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $d = $this->validated(Fail::body($request), false);
        $customer = $this->resolveCustomer($d['customer_id'], $user);
        $deal = $this->resolveDeal($d['deal_id'] ?? null, $customer, $user);
        $lines = $this->cleanLines($d['lines'] ?? []);

        $quote = DB::transaction(function () use ($d, $customer, $deal, $lines, $user) {
            $fy = self::fy2();
            $q = new Quote([
                'quote_no' => Numbers::next("quote_seq_$fy", "IR-Q-$fy-", 4), 'version' => 1, 'status' => 'draft',
                'customer_id' => $customer->id, 'deal_id' => $deal?->id, 'created_by' => $user->id,
                'owner_id' => ($deal?->owner_id) ?: ($customer->owner_id ?: $user->id),
                'issue_date' => now('Asia/Kolkata')->toDateString(),
                'valid_until' => now('Asia/Kolkata')->addDays((int) config('crm.quote_valid_days', 14))->toDateString(),
                'destination' => $deal?->destination, 'travel_date' => $deal?->travel_date?->format('Y-m-d'), 'duration_days' => $deal?->duration_days,
                'adults' => $deal?->adults ?? 1, 'children' => $deal?->children ?? 0,
            ]);
            $this->fill($q, $d);
            if (array_key_exists('owner_id', $d) && ctype_digit((string) $d['owner_id']) && User::where('id', (int) $d['owner_id'])->where('active', true)->exists()) {
                $q->owner_id = (int) $d['owner_id'];
            }
            $q->save();
            $this->writeLines($q, $lines, (float) ($d['tcs_rate'] ?? 0));

            return $q;
        });
        ActivityLogger::log($user, 'create_quote', 'quote', $quote->id, null, $this->snapshot($quote));

        return $this->find((string) $quote->id, $user)->toApi(true);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $quote = $this->find($id, $user);
        if ($quote->status !== 'draft') {
            Fail::http(409, 'Only draft quotes can be edited. Use Revise to make a new version.');
        }
        $d = $this->validated(Fail::body($request), true);
        $before = $this->snapshot($quote);

        DB::transaction(function () use ($quote, $d, $user) {
            if (array_key_exists('customer_id', $d) && (string) $d['customer_id'] !== (string) $quote->customer_id) {
                $quote->customer_id = $this->resolveCustomer($d['customer_id'], $user)->id;
                $quote->deal_id = null;
            }
            if (array_key_exists('deal_id', $d)) {
                $quote->deal_id = $this->resolveDeal($d['deal_id'], Customer::findOrFail($quote->customer_id), $user)?->id;
            }
            if (array_key_exists('owner_id', $d) && ctype_digit((string) $d['owner_id']) && User::where('id', (int) $d['owner_id'])->where('active', true)->exists()) {
                $quote->owner_id = (int) $d['owner_id'];
            }
            $this->fill($quote, $d);
            $quote->save();
            $lines = array_key_exists('lines', $d) ? $this->cleanLines($d['lines']) : $this->cleanLines($quote->lines->map(fn ($l) => $l->toArray())->all());
            $this->writeLines($quote, $lines, (float) ($d['tcs_rate'] ?? $quote->tcs_rate));
        });
        ActivityLogger::log($user, 'update_quote', 'quote', $quote->id, $before, $this->snapshot($quote->fresh()));

        return $this->find($id, $user)->toApi(true);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $quote = $this->find($id, $user);
        if ($quote->status !== 'draft') {
            Fail::http(409, 'Only draft quotes can be deleted.');
        }
        $quote->delete();
        ActivityLogger::log($user, 'delete_quote', 'quote', $id, $this->snapshot($quote), null);

        return ['ok' => true];
    }

    /** New draft version of a sent or rejected quote; the old one is marked superseded. */
    public function revise(Request $request, string $id)
    {
        $user = $request->user();
        $old = $this->find($id, $user);
        if (! in_array($old->status, ['sent', 'rejected'], true)) {
            Fail::http(409, 'Only sent or rejected quotes can be revised.');
        }
        $new = DB::transaction(function () use ($old, $user) {
            $rootId = $old->root_id ?: $old->id;
            $version = (int) Quote::where(fn ($w) => $w->where('id', $rootId)->orWhere('root_id', $rootId))->max('version') + 1;
            $base = preg_replace('/-R\d+$/', '', $old->quote_no);
            $copy = $old->replicate(['sent_at', 'accepted_at', 'reject_reason']);
            $copy->fill(['quote_no' => "$base-R$version", 'root_id' => $rootId, 'version' => $version, 'status' => 'draft', 'created_by' => $user->id,
                'issue_date' => now('Asia/Kolkata')->toDateString(),
                'valid_until' => now('Asia/Kolkata')->addDays((int) config('crm.quote_valid_days', 14))->toDateString()]);
            $copy->save();
            foreach ($old->lines as $l) {
                QuoteLine::create(collect($l->toArray())->except('id', 'quote_id')->all() + ['quote_id' => $copy->id]);
            }
            $old->status = 'superseded';
            $old->save();

            return $copy;
        });
        ActivityLogger::log($user, 'revise_quote', 'quote', $new->id, ['from' => $old->quote_no], $this->snapshot($new));

        return $this->find((string) $new->id, $user)->toApi(true);
    }

    /** Manual status change: sent (without e-mail), accepted, rejected (reason), or reopen to sent. */
    public function setStatus(Request $request, string $id)
    {
        $user = $request->user();
        $quote = $this->find($id, $user);
        $d = Fail::validate(Fail::body($request), ['status' => 'required|in:sent,accepted,rejected', 'reason' => 'nullable|string|max:300']);
        $to = $d['status'];
        $allowed = ['draft' => ['sent'], 'sent' => ['accepted', 'rejected'], 'accepted' => ['sent'], 'rejected' => ['sent']];
        if (! in_array($to, $allowed[$quote->status] ?? [], true)) {
            Fail::http(409, "A {$quote->status} quote cannot be marked $to.");
        }
        $before = $this->snapshot($quote);
        if ($to === 'rejected') {
            $quote->reject_reason = $this->txt($d['reason'] ?? null, 300);
        }
        if ($to === 'sent') {
            $quote->sent_at = $quote->sent_at ?: now();
            $quote->accepted_at = null;
        }
        if ($to === 'accepted') {
            $quote->accepted_at = now();
        }
        $quote->status = $to;
        $quote->save();
        $this->syncDeal($quote, $to, $user);
        ActivityLogger::log($user, 'quote_'.$to, 'quote', $quote->id, $before, $this->snapshot($quote));

        return $this->find($id, $user)->toApi(true);
    }

    public function pdf(Request $request, string $id)
    {
        $quote = $this->find($id, $request->user());

        return response(QuotePdf::render($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.QuotePdf::filename($quote).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function emailPreview(Request $request, string $id)
    {
        $quote = $this->find($id, $request->user());

        return [
            'to' => array_values(array_filter([$quote->customer->email])),
            'subject' => "Your quotation {$quote->quote_no} — {$quote->title}",
            'message' => $this->defaultMessage($quote),
            'filename' => QuotePdf::filename($quote),
        ];
    }

    private function defaultMessage(Quote $q): string
    {
        $co = config('crm.company.name');
        $trip = trim(($q->destination ?: $q->title).($q->travel_date ? ' ('.$q->travel_date->format('d M Y').')' : ''));

        return "Dear {$q->customer->name},\n\nThank you for your interest in travelling with $co. Please find attached our quotation for $trip.".
            ($q->valid_until ? "\n\nThis quote is valid until ".$q->valid_until->format('d M Y').'.' : '').
            "\n\nReply to this e-mail if you would like to change anything or go ahead with the booking.\n\nWarm regards,\n$co";
    }

    private function htmlBody(Quote $q, string $message): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $money = fn ($n) => '₹'.number_format((float) $n, 2);
        $rows = '';
        foreach ($q->lines as $l) {
            $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #e5e5e5">'.$e($l->description).'</td>'
                .'<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:right;white-space:nowrap">'.$money($l->amount + $l->tax).'</td></tr>';
        }
        $co = $e(config('crm.company.name'));

        return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1a1a1a;max-width:640px">'
            .'<div style="white-space:pre-line">'.$e($message).'</div>'
            .'<table style="width:100%;border-collapse:collapse;margin-top:18px;font-size:13px">'
            .'<tr><td colspan="2" style="padding:8px;background:#1a1a1a;color:#fff"><strong>'.$e($q->title).'</strong> · '.$e($q->quote_no).'</td></tr>'
            .$rows
            .'<tr><td style="padding:8px;text-align:right"><strong>Total (incl. taxes)</strong></td><td style="padding:8px;text-align:right;white-space:nowrap"><strong>'.$money($q->total).'</strong></td></tr>'
            .'</table><p style="color:#777;font-size:12px;margin-top:18px">'.$co.'</p></div>';
    }

    public function send(Request $request, string $id)
    {
        $user = $request->user();
        $quote = $this->find($id, $user);
        if (in_array($quote->status, ['superseded', 'rejected'], true)) {
            Fail::http(409, "A {$quote->status} quote cannot be e-mailed. Revise it first.");
        }
        $d = Fail::validate(Fail::body($request), [
            'to' => 'required|array|min:1|max:10', 'to.*' => 'required|email|max:200',
            'cc' => 'nullable|array|max:10', 'cc.*' => 'email|max:200',
            'subject' => 'required|string|max:300', 'message' => 'required|string|max:10000', 'attach_pdf' => 'sometimes|boolean',
        ]);
        if ($quote->lines->isEmpty()) {
            Fail::unprocessable(['lines' => 'Add at least one price line before sending.']);
        }
        $withPdf = ! array_key_exists('attach_pdf', $d) || (bool) $d['attach_pdf'];
        $attachments = $withPdf ? [['name' => QuotePdf::filename($quote), 'type' => 'application/pdf', 'bytes' => QuotePdf::render($quote)]] : [];

        $res = MailSender::send($d['to'], $d['subject'], $this->htmlBody($quote, $d['message']), $d['cc'] ?? [], [], true, $attachments);
        $failed = ($res['status'] ?? '') === 'failed';
        QuoteEmail::create(['quote_id' => $quote->id, 'to_list' => implode(', ', $d['to']), 'subject' => $d['subject'], 'status' => $res['status'],
            'error' => $res['error'] ?? null, 'with_pdf' => $withPdf, 'sent_by' => $user->id, 'created_at' => now()]);
        ActivityLogger::log($user, $failed ? 'quote_email_failed' : 'quote_email_sent', 'quote', $quote->id, null, ['to' => $d['to'], 'status' => $res['status']]);
        if ($failed) {
            Fail::http(502, 'The e-mail could not be sent: '.($res['error'] ?? 'unknown error'));
        }

        if (in_array($quote->status, ['draft', 'sent'], true)) {
            $quote->status = 'sent';
            $quote->sent_at = now();
            $quote->save();
            $this->syncDeal($quote, 'sent', $user);
        }

        return ['status' => $res['status'], 'note' => $res['note'] ?? null, 'quote' => $this->find($id, $user)->toApi(true)];
    }
}

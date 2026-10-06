<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Billing;
use App\Services\CustomerMail;
use App\Services\DocPdf;
use App\Support\ActivityLogger;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceiptController extends Controller
{
    private const WITH = ['customer', 'invoice:id,invoice_no,title,status', 'creator:id,name'];

    // ------------------------------------------------------------------ helpers

    /** Visible: everything with invoices.view_all; otherwise receipts they entered, on invoices they can see, or advances of customers they can see. */
    public static function scopeForUser($query, User $user)
    {
        if (! $user->hasPermission('invoices.view_all')) {
            $query->where(function ($w) use ($user) {
                $w->where('receipts.created_by', $user->id)
                    ->orWhereIn('receipts.invoice_id', InvoiceController::scopeForUser(Invoice::query(), $user)->select('invoices.id'))
                    ->orWhere(fn ($x) => $x->whereNull('receipts.invoice_id')
                        ->whereIn('receipts.customer_id', CustomerController::scopeForUser(\App\Models\Customer::query(), $user)->select('customers.id')));
            });
        }

        return $query;
    }

    private function find(string $id, User $user): Receipt
    {
        $r = ctype_digit($id) ? self::scopeForUser(Receipt::query()->with(self::WITH), $user)->where('receipts.id', (int) $id)->first() : null;
        if (! $r) {
            Fail::notFound('Receipt not found');
        }

        return $r;
    }

    private function today(): string
    {
        return now('Asia/Kolkata')->toDateString();
    }

    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = is_scalar($v) ? substr((string) $v, 0, 10) : '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || strtotime($v) === false) {
            Fail::unprocessable(['received_date' => 'Invalid date (use YYYY-MM-DD)']);
        }

        return $v;
    }

    private function pdfData(Receipt $r): array
    {
        $r->loadMissing(['customer', 'invoice']);

        return ['r' => $r];
    }

    // ------------------------------------------------------------------ routes

    public function index(Request $request)
    {
        $user = $request->user();
        $q = self::scopeForUser(Receipt::query()->with(self::WITH), $user);
        foreach (['mode', 'kind', 'status'] as $f) {
            if (($v = (string) $request->query($f, '')) !== '') {
                $q->where("receipts.$f", $v);
            }
        }
        foreach (['customer_id', 'invoice_id'] as $f) {
            if (($v = (string) $request->query($f, '')) !== '') {
                $q->where("receipts.$f", ctype_digit($v) ? (int) $v : -1);
            }
        }
        if (($v = (string) $request->query('advances', '')) === '1') {
            $q->whereNull('receipts.invoice_id')->where('receipts.kind', 'receipt')->where('receipts.status', 'valid');
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(function ($w) use ($like, $op) {
                $w->where('receipts.receipt_no', $op, $like)->orWhere('receipts.reference', $op, $like)
                    ->orWhereIn('receipts.customer_id', \App\Models\Customer::query()->select('id')->where(fn ($c) => $c->where('name', $op, $like)->orWhere('phone', $op, $like)))
                    ->orWhereIn('receipts.invoice_id', Invoice::query()->select('id')->where('invoice_no', $op, $like));
            });
        }
        $q->orderByDesc('receipts.received_date')->orderByDesc('receipts.id');

        if (Paging::requested($request)) {
            $res = Paging::paginate($request, $q, fn (Receipt $r) => $r->toApi());
            $base = self::scopeForUser(Receipt::query(), $user)->where('receipts.status', 'valid');
            $res['totals'] = [
                'received' => round((float) (clone $base)->where('kind', 'receipt')->sum('amount'), 2),
                'refunded' => round((float) (clone $base)->where('kind', 'refund')->sum('amount'), 2),
                'advances' => round((float) (clone $base)->where('kind', 'receipt')->whereNull('invoice_id')->sum('amount'), 2),
            ];

            return $res;
        }
        $limit = max(1, (int) $request->query('limit', 500));

        return $q->limit($limit)->get()->map(fn (Receipt $r) => $r->toApi())->values();
    }

    public function show(Request $request, string $id)
    {
        return $this->find($id, $request->user())->toApi();
    }

    /** POST /customers/{id}/advances: money received before an invoice exists. */
    public function addAdvance(Request $request, string $id)
    {
        $user = $request->user();
        $customer = CustomerController::find($id, $user);
        $d = Fail::validate(Fail::body($request), [
            'amount' => 'required|numeric|min:0.01|max:100000000', 'received_date' => 'nullable|string',
            'mode' => 'nullable|in:'.implode(',', Receipt::MODES), 'reference' => 'nullable|string|max:120',
            'deposited_to' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:1000',
        ]);
        $d['received_date'] = $this->date($d['received_date'] ?? null) ?? $this->today();
        if ($d['received_date'] > $this->today()) {
            Fail::unprocessable(['received_date' => 'The payment date cannot be in the future']);
        }
        $r = DB::transaction(fn () => Billing::record($user, $customer->id, null, 'receipt', $d));

        return $this->find((string) $r->id, $user)->toApi();
    }

    /** Apply an advance to an issued invoice of the same customer; the whole receipt must fit within the balance. */
    public function apply(Request $request, string $id)
    {
        $user = $request->user();
        $r = $this->find($id, $user);
        $d = Fail::validate(Fail::body($request), ['invoice_id' => 'required']);
        if ($r->kind !== 'receipt' || $r->status !== 'valid' || $r->invoice_id !== null) {
            Fail::http(409, 'Only an unapplied advance can be applied to an invoice.');
        }
        $inv = ctype_digit((string) $d['invoice_id']) ? InvoiceController::scopeForUser(Invoice::query()->with('creditNotes'), $user)->where('invoices.id', (int) $d['invoice_id'])->first() : null;
        if (! $inv || (int) $inv->customer_id !== (int) $r->customer_id || $inv->status !== 'issued') {
            Fail::unprocessable(['invoice_id' => 'Choose an issued invoice of the same customer']);
        }
        if ($r->amount > $inv->balance() + 0.005) {
            Fail::unprocessable(['invoice_id' => sprintf('This advance (₹%s) is more than the invoice balance (₹%s).', number_format($r->amount, 2), number_format($inv->balance(), 2))]);
        }
        DB::transaction(function () use ($r, $inv, $user) {
            $r->invoice_id = $inv->id;
            $r->deal_id = $inv->deal_id;
            $r->save();
            Billing::settle($inv);
            Billing::dealBooked($inv, $user);
            ActivityLogger::log($user, 'apply_advance', 'receipt', $r->id, null, ['receipt_no' => $r->receipt_no, 'invoice_id' => $inv->id]);
        });

        return $this->find($id, $user)->toApi();
    }

    public function cancel(Request $request, string $id)
    {
        $user = $request->user();
        $r = $this->find($id, $user);
        $d = Fail::validate(Fail::body($request), ['reason' => 'required|string|max:300']);
        if ($r->status !== 'valid') {
            Fail::http(409, 'This receipt is already cancelled.');
        }
        DB::transaction(function () use ($r, $d, $user) {
            $r->status = 'cancelled';
            $r->cancelled_at = now();
            $r->cancel_reason = trim($d['reason']);
            $r->save();
            if ($r->invoice_id && ($inv = Invoice::find($r->invoice_id))) {
                Billing::settle($inv);
            }
            ActivityLogger::log($user, 'cancel_receipt', 'receipt', $r->id, ['status' => 'valid'], ['status' => 'cancelled', 'receipt_no' => $r->receipt_no, 'amount' => $r->amount]);
        });

        return $this->find($id, $user)->toApi();
    }

    public function pdf(Request $request, string $id)
    {
        $r = $this->find($id, $request->user());

        return response(DocPdf::render('pdf.receipt', $this->pdfData($r)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.DocPdf::filename($r->receipt_no).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function emailPreview(Request $request, string $id)
    {
        $r = $this->find($id, $request->user());
        $co = config('crm.company.name');
        $what = $r->kind === 'refund' ? 'refund' : 'payment';

        return [
            'to' => array_values(array_filter([$r->customer->email])),
            'subject' => ($r->kind === 'refund' ? 'Refund' : 'Payment receipt')." {$r->receipt_no}",
            'message' => "Dear {$r->customer->name},\n\nThank you. Please find attached the receipt for your $what of ₹".number_format($r->amount, 2).
                ($r->invoice ? " against invoice {$r->invoice->invoice_no}" : '').".\n\nWarm regards,\n$co",
            'filename' => DocPdf::filename($r->receipt_no),
        ];
    }

    public function send(Request $request, string $id)
    {
        $user = $request->user();
        $r = $this->find($id, $user);
        if ($r->status !== 'valid') {
            Fail::http(409, 'A cancelled receipt cannot be e-mailed.');
        }
        $d = Fail::validate(Fail::body($request), [
            'to' => 'required|array|min:1|max:10', 'to.*' => 'required|email|max:200', 'cc' => 'nullable|array|max:10', 'cc.*' => 'email|max:200',
            'subject' => 'required|string|max:300', 'message' => 'required|string|max:10000', 'attach_pdf' => 'sometimes|boolean',
        ]);
        $pdf = (! array_key_exists('attach_pdf', $d) || $d['attach_pdf'])
            ? ['name' => DocPdf::filename($r->receipt_no), 'type' => 'application/pdf', 'bytes' => DocPdf::render('pdf.receipt', $this->pdfData($r))] : null;
        $res = CustomerMail::send($d['to'], $d['cc'] ?? [], $d['subject'], $d['message'], $r->receipt_no, [[ucfirst(str_replace('_', ' ', $r->mode)).($r->reference ? " · {$r->reference}" : ''), $r->amount]],
            $r->kind === 'refund' ? 'Amount refunded' : 'Amount received', $r->amount, $pdf);
        $failed = ($res['status'] ?? '') === 'failed';
        DB::table('invoice_emails')->insert(['doc_type' => 'receipt', 'doc_id' => $r->id, 'to_list' => implode(', ', $d['to']), 'subject' => $d['subject'],
            'status' => $res['status'], 'error' => $res['error'] ?? null, 'sent_by' => $user->id, 'created_at' => now()]);
        if ($failed) {
            Fail::http(502, 'The e-mail could not be sent: '.($res['error'] ?? 'unknown error'));
        }

        return ['status' => $res['status'], 'note' => $res['note'] ?? null];
    }
}

<?php

namespace App\Services\Mail;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

/**
 * Finds the customer / deal / quote / invoice an inbound mail belongs to. Read-only.
 * Priority: (a) conversation id of a mail we sent  (b) In-Reply-To / References  (c) document number in the subject  (d) sender address.
 *
 * @phpstan-type Match array{customer_id:int,deal_id:?int,quote_id:?int,invoice_id:?int,by:string}
 */
class CustomerReplyMatcher
{
    public const QUOTE_NO = '/\bIR-Q-\d{2}-\d{3,6}(?:-R\d+)?\b/i';
    public const INVOICE_NO = '/\b(?:INV|RCT|RFD|CN)\/\d{2}-\d{2}\/\d{3,6}\b/i';
    public const DEAL_NO = '/\bIRD-\d{3,8}\b/i';

    /** @return array{customer_id:int,deal_id:?int,quote_id:?int,invoice_id:?int,by:string}|null */
    public function match(InboundMessage $m): ?array
    {
        // (a) thread
        if ($m->conversationId) {
            foreach (['quote_emails', 'invoice_emails'] as $t) {
                $row = DB::table($t)->where('conversation_id', $m->conversationId)->orderByDesc('id')->first();
                if ($row && ($hit = $this->fromSent($t, $row, 'thread'))) {
                    return $hit;
                }
            }
        }

        // (b) In-Reply-To / References
        $ids = $this->idVariants(array_merge($m->inReplyTo ? [$m->inReplyTo] : [], $m->references));
        if ($ids) {
            foreach (['quote_emails', 'invoice_emails'] as $t) {
                $row = DB::table($t)->whereIn('internet_message_id', $ids)->orderByDesc('id')->first();
                if ($row && ($hit = $this->fromSent($t, $row, 'reference'))) {
                    return $hit;
                }
            }
        }

        // (c) a document number in the subject
        if (preg_match_all(self::QUOTE_NO, $m->subject, $mm)) {
            foreach ($mm[0] as $no) {
                $q = Quote::whereRaw('UPPER(quote_no) = ?', [strtoupper($no)])->first();
                if ($q) {
                    return ['customer_id' => (int) $q->customer_id, 'deal_id' => $q->deal_id ? (int) $q->deal_id : null, 'quote_id' => (int) $q->id, 'invoice_id' => null, 'by' => 'subject'];
                }
            }
        }
        if (preg_match_all(self::INVOICE_NO, $m->subject, $mm)) {
            foreach ($mm[0] as $no) {
                $no = strtoupper($no);
                $inv = Invoice::where('invoice_no', $no)->first();
                if (! $inv && ($r = Receipt::where('receipt_no', $no)->first()) && $r->invoice_id) {
                    $inv = Invoice::find($r->invoice_id);
                }
                if ($inv) {
                    return ['customer_id' => (int) $inv->customer_id, 'deal_id' => $inv->deal_id ? (int) $inv->deal_id : null, 'quote_id' => null, 'invoice_id' => (int) $inv->id, 'by' => 'subject'];
                }
            }
        }
        if (preg_match_all(self::DEAL_NO, $m->subject, $mm)) {
            foreach ($mm[0] as $no) {
                $d = Deal::whereRaw('UPPER(deal_no) = ?', [strtoupper($no)])->first();
                if ($d) {
                    return ['customer_id' => (int) $d->customer_id, 'deal_id' => (int) $d->id, 'quote_id' => null, 'invoice_id' => null, 'by' => 'subject'];
                }
            }
        }

        // (d) the sender is one customer's e-mail address; a deal is attached only when it is unambiguous
        $sender = mb_strtolower(trim($m->fromEmail));
        if ($sender !== '') {
            $ids = Customer::whereRaw('LOWER(email) = ?', [$sender])->pluck('id')
                ->merge(CustomerContact::whereRaw('LOWER(email) = ?', [$sender])->pluck('customer_id'))->unique()->values();
            if ($ids->count() === 1) {
                $cid = (int) $ids[0];
                $open = Deal::where('customer_id', $cid)->whereNotIn('stage', ['won', 'lost'])->pluck('id');

                return ['customer_id' => $cid, 'deal_id' => $open->count() === 1 ? (int) $open[0] : null, 'quote_id' => null, 'invoice_id' => null, 'by' => 'sender'];
            }
        }

        return null;
    }

    /** @return array{customer_id:int,deal_id:?int,quote_id:?int,invoice_id:?int,by:string}|null */
    private function fromSent(string $table, object $row, string $by): ?array
    {
        if ($table === 'quote_emails') {
            $q = Quote::find($row->quote_id);

            return $q ? ['customer_id' => (int) $q->customer_id, 'deal_id' => $q->deal_id ? (int) $q->deal_id : null, 'quote_id' => (int) $q->id, 'invoice_id' => null, 'by' => $by] : null;
        }
        $inv = $row->doc_type === 'receipt'
            ? ($this->receiptInvoice((int) $row->doc_id) ?? null)
            : Invoice::find($row->doc_id);
        if (! $inv && $row->doc_type === 'receipt' && ($r = Receipt::find($row->doc_id))) {
            return ['customer_id' => (int) $r->customer_id, 'deal_id' => $r->deal_id ? (int) $r->deal_id : null, 'quote_id' => null, 'invoice_id' => null, 'by' => $by];
        }

        return $inv ? ['customer_id' => (int) $inv->customer_id, 'deal_id' => $inv->deal_id ? (int) $inv->deal_id : null, 'quote_id' => null, 'invoice_id' => (int) $inv->id, 'by' => $by] : null;
    }

    private function receiptInvoice(int $receiptId): ?Invoice
    {
        $r = Receipt::find($receiptId);

        return $r && $r->invoice_id ? Invoice::find($r->invoice_id) : null;
    }

    /** @return list<string> */
    private function idVariants(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $bare = trim(trim((string) $id), '<>');
            if ($bare !== '') {
                array_push($out, $bare, "<$bare>");
            }
        }

        return array_values(array_unique($out));
    }
}

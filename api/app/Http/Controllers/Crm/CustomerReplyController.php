<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\CustomerReply;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Http\Request;

/** Replies customers sent to our quote / invoice / receipt e-mails, as matched by the mailbox poller. Visible wherever the record is. */
class CustomerReplyController extends Controller
{
    private function list(string $column, int $id)
    {
        return CustomerReply::with(['customer:id,name', 'deal:id,deal_no,name', 'quote:id,quote_no', 'invoice:id,invoice_no'])
            ->where($column, $id)->orderByDesc('received_at')->limit(100)->get()->map(fn (CustomerReply $r) => $r->toApi())->values();
    }

    public function customer(Request $request, string $id)
    {
        return $this->list('customer_id', CustomerController::find($id, $request->user())->id);
    }

    public function deal(Request $request, string $id)
    {
        return $this->list('deal_id', DealController::find($id, $request->user())->id);
    }

    public function quote(Request $request, string $id)
    {
        $q = ctype_digit($id) ? QuoteController::scopeForUser(Quote::query(), $request->user())->where('quotes.id', (int) $id)->first() : null;
        $q ?? Fail::notFound('Quote not found');

        return $this->list('quote_id', $q->id);
    }

    public function invoice(Request $request, string $id)
    {
        return $this->list('invoice_id', InvoiceController::find($id, $request->user())->id);
    }
}

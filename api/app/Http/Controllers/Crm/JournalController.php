<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Ledger;
use App\Support\ActivityLogger;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class JournalController extends Controller
{
    private function find(string $id): JournalEntry
    {
        $e = ctype_digit($id) ? JournalEntry::with('lines.account')->find((int) $id) : null;
        if (! $e) {
            Fail::notFound('Journal entry not found');
        }

        return $e;
    }

    private function date(mixed $v, string $field = 'entry_date'): string
    {
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

    public function index(Request $request)
    {
        $q = JournalEntry::query()->with('lines');
        if (($v = (string) $request->query('source', '')) !== '') {
            $q->where('source_type', $v);
        }
        if (($v = (string) $request->query('from', '')) !== '') {
            $q->where('entry_date', '>=', $this->date($v, 'from'));
        }
        if (($v = (string) $request->query('to', '')) !== '') {
            $q->where('entry_date', '<=', $this->date($v, 'to'));
        }
        if (($v = (string) $request->query('account_id', '')) !== '') {
            $q->whereIn('id', \App\Models\JournalLine::query()->select('entry_id')->where('account_id', ctype_digit($v) ? (int) $v : -1));
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(fn ($w) => $w->where('entry_no', $op, $like)->orWhere('narration', $op, $like));
        }
        $q->orderByDesc('entry_date')->orderByDesc('id');

        if (Paging::requested($request)) {
            return Paging::paginate($request, $q, fn (JournalEntry $e) => $e->toApi());
        }

        return $q->limit(max(1, (int) $request->query('limit', 500)))->get()->map(fn (JournalEntry $e) => $e->toApi())->values();
    }

    public function show(string $id)
    {
        return $this->find($id)->toApi(true);
    }

    /** Manual entry. Lines: [{account_id, debit?, credit?, memo?}]. Must balance. */
    public function store(Request $request)
    {
        $user = $request->user();
        $d = Fail::validate(Fail::body($request), [
            'entry_date' => 'required|string', 'narration' => 'required|string|max:300',
            'lines' => 'required|array|min:2|max:60',
        ]);
        $date = $this->date($d['entry_date']);
        if ($date > now('Asia/Kolkata')->toDateString()) {
            Fail::unprocessable(['entry_date' => 'The date cannot be in the future']);
        }
        $lines = [];
        foreach ($d['lines'] as $i => $l) {
            $acc = is_array($l) && isset($l['account_id']) && ctype_digit((string) $l['account_id']) ? Account::where('id', (int) $l['account_id'])->where('active', true)->first() : null;
            if (! $acc) {
                Fail::unprocessable(["lines.$i.account_id" => 'Choose an active account']);
            }
            $lines[] = ['account_id' => $acc->id, 'debit' => (float) ($l['debit'] ?? 0), 'credit' => (float) ($l['credit'] ?? 0), 'memo' => $l['memo'] ?? null];
        }
        $e = Ledger::post($date, trim($d['narration']), $lines, 'manual', null, $user);
        ActivityLogger::log($user, 'create_journal', 'journal', $e->id, null, ['entry_no' => $e->entry_no, 'narration' => $e->narration]);

        return $this->find((string) $e->id)->toApi(true);
    }

    /** Only manual entries are reversed by hand; sales postings are undone by cancelling the document. */
    public function reverse(Request $request, string $id)
    {
        $e = $this->find($id);
        if ($e->source_type !== 'manual') {
            Fail::http(409, 'Entries made from invoices and receipts are reversed by cancelling the document.');
        }
        $d = Fail::validate(Fail::body($request), ['entry_date' => 'nullable|string', 'reason' => 'nullable|string|max:200']);
        $date = isset($d['entry_date']) && $d['entry_date'] !== '' ? $this->date($d['entry_date']) : null;
        $r = Ledger::reverse($e, $request->user(), $date, ! empty($d['reason']) ? 'Reversal of '.$e->entry_no.' — '.$d['reason'] : null);

        return $this->find((string) $r->id)->toApi(true);
    }
}

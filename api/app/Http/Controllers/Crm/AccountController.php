<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\JournalLine;
use App\Services\Ledger;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Chart of accounts and the bank / cash accounts that sit on it. */
class AccountController extends Controller
{
    private function find(string $id): Account
    {
        $a = ctype_digit($id) ? Account::find((int) $id) : null;
        if (! $a) {
            Fail::notFound('Account not found');
        }

        return $a;
    }

    private function row(Account $a, array $bal): array
    {
        $b = $bal[$a->id] ?? ['debit' => 0.0, 'credit' => 0.0];

        return $a->toArray() + [] + [
            'debit' => $b['debit'], 'credit' => $b['credit'], 'balance' => $a->balanceOf($b['debit'], $b['credit']),
            'used' => isset($bal[$a->id]), 'type_label' => Account::TYPE_LABELS[$a->type] ?? $a->type,
        ];
    }

    // ------------------------------------------------------------------ chart

    public function index(Request $request)
    {
        $bal = Ledger::balances();
        $q = Account::query()->orderBy('code');
        if (($t = (string) $request->query('type', '')) !== '') {
            $q->where('type', $t);
        }
        if ((string) $request->query('active', '') === '1') {
            $q->where('active', true);
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(fn ($w) => $w->where('code', $op, $like)->orWhere('name', $op, $like));
        }

        return ['items' => $q->get()->map(fn (Account $a) => $this->row($a, $bal))->values(), 'types' => Account::TYPES];
    }

    private function validated(array $b, bool $partial): array
    {
        return Fail::validate($b, [
            'code' => ($partial ? 'sometimes|' : 'required|').'string|max:12|regex:/^[A-Za-z0-9._-]+$/',
            'name' => ($partial ? 'sometimes|required' : 'required').'|string|max:150',
            'type' => ($partial ? 'sometimes|' : 'required|').'in:'.implode(',', Account::TYPES),
            'description' => 'nullable|string|max:500', 'active' => 'sometimes|boolean',
        ]);
    }

    public function store(Request $request)
    {
        $d = $this->validated(Fail::body($request), false);
        if (Account::where('code', $d['code'])->exists()) {
            Fail::http(409, "Account code {$d['code']} is already used.");
        }
        $a = Account::create(['code' => $d['code'], 'name' => trim($d['name']), 'type' => $d['type'], 'description' => $d['description'] ?? null, 'is_system' => false, 'active' => true]);
        ActivityLogger::log($request->user(), 'create_account', 'account', $a->id, null, ['code' => $a->code, 'name' => $a->name]);

        return $this->row($a, []);
    }

    public function update(Request $request, string $id)
    {
        $a = $this->find($id);
        $d = $this->validated(Fail::body($request), true);
        $used = JournalLine::where('account_id', $a->id)->exists();
        if (isset($d['code']) && $d['code'] !== $a->code) {
            if ($a->is_system || $used) {
                Fail::http(409, 'The code of a system account or an account with postings cannot be changed.');
            }
            if (Account::where('code', $d['code'])->exists()) {
                Fail::http(409, "Account code {$d['code']} is already used.");
            }
            $a->code = $d['code'];
        }
        if (isset($d['type']) && $d['type'] !== $a->type) {
            if ($a->is_system || $used) {
                Fail::http(409, 'The type of a system account or an account with postings cannot be changed.');
            }
            $a->type = $d['type'];
        }
        if (isset($d['name'])) {
            $a->name = trim($d['name']);
        }
        if (array_key_exists('description', $d)) {
            $a->description = $d['description'];
        }
        if (array_key_exists('active', $d)) {
            if ($a->is_system && ! $d['active']) {
                Fail::http(409, 'System accounts are needed for automatic postings and cannot be deactivated.');
            }
            $a->active = (bool) $d['active'];
        }
        $a->save();
        Ledger::flush();
        ActivityLogger::log($request->user(), 'update_account', 'account', $a->id, null, ['code' => $a->code, 'name' => $a->name, 'active' => $a->active]);

        return $this->row($a, Ledger::balances());
    }

    public function destroy(Request $request, string $id)
    {
        $a = $this->find($id);
        if ($a->is_system) {
            Fail::http(409, 'System accounts cannot be deleted.');
        }
        if (JournalLine::where('account_id', $a->id)->exists() || BankAccount::where('account_id', $a->id)->exists()) {
            Fail::http(409, 'This account has postings. Deactivate it instead.');
        }
        $a->delete();
        ActivityLogger::log($request->user(), 'delete_account', 'account', $id, ['code' => $a->code, 'name' => $a->name], null);

        return ['ok' => true];
    }

    // ------------------------------------------------------------------ banking

    private function bankRow(BankAccount $b, array $bal): array
    {
        $x = $bal[$b->account_id] ?? ['debit' => 0.0, 'credit' => 0.0];

        return $b->toArray() + ['account_code' => $b->account?->code, 'balance' => round($x['debit'] - $x['credit'], 2)];
    }

    public function banks(Request $request)
    {
        $bal = Ledger::balances();
        $rows = BankAccount::with('account')->orderByDesc('is_default')->orderBy('name')->get()->map(fn ($b) => $this->bankRow($b, $bal))->values();

        return ['items' => $rows, 'total' => round((float) $rows->where('active', true)->sum('balance'), 2)];
    }

    public function bankOptions()
    {
        return BankAccount::where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'kind', 'is_default'])
            ->map(fn ($b) => ['id' => (string) $b->id, 'name' => $b->name, 'kind' => $b->kind, 'is_default' => (bool) $b->is_default])->values();
    }

    public function storeBank(Request $request)
    {
        $user = $request->user();
        $d = Fail::validate(Fail::body($request), [
            'name' => 'required|string|max:120', 'kind' => 'nullable|in:'.implode(',', BankAccount::KINDS), 'bank_name' => 'nullable|string|max:120',
            'last4' => 'nullable|string|max:8', 'is_default' => 'sometimes|boolean',
            'opening_balance' => 'nullable|numeric', 'opening_date' => 'nullable|string',
        ]);
        $b = DB::transaction(function () use ($d, $user) {
            // each bank gets its own ledger account in the 1011..1099 range
            $next = (int) Account::whereBetween('code', ['1011', '1099'])->max(DB::raw('CAST(code AS INTEGER)'));
            $code = (string) max(1011, $next + 1);
            $acc = Account::create(['code' => $code, 'name' => trim($d['name']), 'type' => 'asset', 'subtype' => ($d['kind'] ?? 'bank') === 'cash' ? 'cash' : 'bank', 'is_system' => false, 'active' => true]);
            $first = ! BankAccount::exists();
            $b = BankAccount::create(['account_id' => $acc->id, 'name' => trim($d['name']), 'kind' => $d['kind'] ?? 'bank', 'bank_name' => $d['bank_name'] ?? null,
                'last4' => $d['last4'] ?? null, 'is_default' => ! empty($d['is_default']) || $first, 'active' => true]);
            if ($b->is_default) {
                BankAccount::where('id', '!=', $b->id)->update(['is_default' => false]);
            }
            $open = round((float) ($d['opening_balance'] ?? 0), 2);
            if ($open != 0.0) {
                $date = $d['opening_date'] ?? now('Asia/Kolkata')->toDateString();
                $lines = $open > 0
                    ? [['account_id' => $acc->id, 'debit' => $open, 'memo' => 'Opening balance'], ['account_id' => Ledger::id('3200'), 'credit' => $open]]
                    : [['account_id' => $acc->id, 'credit' => -$open, 'memo' => 'Opening balance'], ['account_id' => Ledger::id('3200'), 'debit' => -$open]];
                Ledger::post($date, "Opening balance — {$b->name}", $lines, 'opening', $b->id, $user);
            }

            return $b;
        });
        ActivityLogger::log($user, 'create_bank_account', 'bank_account', $b->id, null, ['name' => $b->name]);

        return $this->bankRow($b->load('account'), Ledger::balances());
    }

    public function updateBank(Request $request, string $id)
    {
        $b = ctype_digit($id) ? BankAccount::with('account')->find((int) $id) : null;
        if (! $b) {
            Fail::notFound('Bank account not found');
        }
        $d = Fail::validate(Fail::body($request), [
            'name' => 'sometimes|required|string|max:120', 'bank_name' => 'nullable|string|max:120', 'last4' => 'nullable|string|max:8',
            'is_default' => 'sometimes|boolean', 'active' => 'sometimes|boolean',
        ]);
        DB::transaction(function () use ($b, $d) {
            foreach (['name', 'bank_name', 'last4'] as $f) {
                if (array_key_exists($f, $d)) {
                    $b->{$f} = $d[$f] === null ? null : trim((string) $d[$f]);
                }
            }
            if (array_key_exists('active', $d)) {
                $b->active = (bool) $d['active'];
                $b->account->active = $b->active;
            }
            if (! empty($d['is_default']) && $b->active) {
                BankAccount::where('id', '!=', $b->id)->update(['is_default' => false]);
                $b->is_default = true;
            }
            if (! $b->active) {
                $b->is_default = false;
            }
            if (isset($d['name'])) {
                $b->account->name = $b->name;
            }
            $b->account->save();
            $b->save();
        });

        return $this->bankRow($b->fresh('account'), Ledger::balances());
    }
}

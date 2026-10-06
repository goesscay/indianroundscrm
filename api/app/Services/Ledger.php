<?php

namespace App\Services;

use App\Crm\Fail;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Double-entry core: every posting must balance, dates inside a closed period are refused, nothing is edited in place. */
class Ledger
{
    /** @var array<string,int> */
    private static array $ids = [];

    public static function flush(): void
    {
        self::$ids = [];
    }

    /** Ledger account id for a chart code (e.g. 1100). */
    public static function id(string $code): int
    {
        return self::$ids[$code] ??= (int) (Account::where('code', $code)->value('id') ?? Fail::http(500, "Ledger account $code is missing from the chart of accounts."));
    }

    /** Where money lands: the chosen bank account, else cash for cash payments, else the default bank account, else the system Bank account. */
    public static function moneyAccount(?int $bankAccountId, string $mode): int
    {
        if ($bankAccountId && ($b = BankAccount::find($bankAccountId))) {
            return (int) $b->account_id;
        }
        if ($mode === 'cash') {
            return self::id('1000');
        }
        $default = BankAccount::where('is_default', true)->where('active', true)->first();

        return $default ? (int) $default->account_id : self::id('1010');
    }

    public static function lockDate(): ?string
    {
        $v = DB::table('accounting_settings')->where('key', 'lock_date')->value('value');

        return $v ?: null;
    }

    public static function assertOpen(string $date): void
    {
        $lock = self::lockDate();
        if ($lock && $date <= $lock) {
            Fail::http(422, sprintf('The books are closed up to %s. Choose a later date.', Carbon::parse($lock)->format('d M Y')));
        }
    }

    /**
     * @param  list<array{account_id:int,debit?:float,credit?:float,memo?:?string,customer_id?:?int,deal_id?:?int,supplier_id?:?int}>  $lines
     */
    public static function post(string $date, string $narration, array $lines, string $sourceType = 'manual', ?int $sourceId = null, ?User $user = null, ?int $reverses = null): JournalEntry
    {
        self::assertOpen($date);
        $lines = array_values(array_filter($lines, fn ($l) => round((float) ($l['debit'] ?? 0), 2) > 0 || round((float) ($l['credit'] ?? 0), 2) > 0));
        if (count($lines) < 2) {
            Fail::unprocessable(['lines' => 'An entry needs at least two lines with amounts.']);
        }
        $dr = $cr = 0.0;
        foreach ($lines as $i => $l) {
            $d = round((float) ($l['debit'] ?? 0), 2);
            $c = round((float) ($l['credit'] ?? 0), 2);
            if ($d < 0 || $c < 0 || ($d > 0 && $c > 0)) {
                Fail::unprocessable(["lines.$i" => 'A line is either a debit or a credit, not both.']);
            }
            $dr += $d;
            $cr += $c;
        }
        if (abs($dr - $cr) > 0.005) {
            Fail::unprocessable(['lines' => sprintf('Debits (%s) and credits (%s) must be equal.', number_format($dr, 2), number_format($cr, 2))]);
        }

        return DB::transaction(function () use ($date, $narration, $lines, $sourceType, $sourceId, $user, $reverses) {
            $e = JournalEntry::create([
                'entry_no' => Billing::nextNumber('JE', Carbon::parse($date, 'Asia/Kolkata')), 'entry_date' => $date, 'narration' => mb_substr($narration, 0, 300),
                'source_type' => $sourceType, 'source_id' => $sourceId, 'reverses_id' => $reverses, 'created_by' => $user?->id,
            ]);
            foreach ($lines as $l) {
                JournalLine::create([
                    'entry_id' => $e->id, 'account_id' => (int) $l['account_id'], 'debit' => round((float) ($l['debit'] ?? 0), 2), 'credit' => round((float) ($l['credit'] ?? 0), 2),
                    'memo' => isset($l['memo']) ? mb_substr((string) $l['memo'], 0, 250) : null, 'customer_id' => $l['customer_id'] ?? null, 'deal_id' => $l['deal_id'] ?? null, 'supplier_id' => $l['supplier_id'] ?? null,
                ]);
            }

            return $e->load('lines.account');
        });
    }

    /** Undo an entry with a mirror entry; both stay on the record. */
    public static function reverse(JournalEntry $e, ?User $user, ?string $date = null, ?string $narration = null): JournalEntry
    {
        if ($e->reversed_at !== null || $e->reverses_id !== null) {
            Fail::http(409, 'This entry has already been reversed (or is itself a reversal).');
        }
        $date ??= now('Asia/Kolkata')->toDateString();
        $lines = $e->lines()->get()->map(fn ($l) => ['account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit, 'memo' => $l->memo, 'customer_id' => $l->customer_id, 'deal_id' => $l->deal_id, 'supplier_id' => $l->supplier_id])->all();

        return DB::transaction(function () use ($e, $user, $date, $lines, $narration) {
            $r = self::post($date, $narration ?? 'Reversal of '.$e->entry_no.' — '.$e->narration, $lines, 'reversal', $e->id, $user, $e->id);
            $e->reversed_at = now();
            $e->save();
            ActivityLogger::log($user, 'reverse_journal', 'journal', $e->id, ['entry_no' => $e->entry_no], ['reversal' => $r->entry_no]);

            return $r;
        });
    }

    /** debit, credit and (own-direction) balance per account, up to and including $asOf (null = everything). */
    public static function balances(?string $asOf = null, ?string $from = null): array
    {
        $q = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->selectRaw('l.account_id, SUM(l.debit) as debit, SUM(l.credit) as credit')->groupBy('l.account_id');
        if ($asOf) {
            $q->where('e.entry_date', '<=', $asOf);
        }
        if ($from) {
            $q->where('e.entry_date', '>=', $from);
        }
        $out = [];
        foreach ($q->get() as $r) {
            $out[(int) $r->account_id] = ['debit' => round((float) $r->debit, 2), 'credit' => round((float) $r->credit, 2)];
        }

        return $out;
    }
}

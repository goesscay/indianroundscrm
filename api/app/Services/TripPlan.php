<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Invoice;
use App\Models\TripTask;
use App\Models\TripTemplate;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** A confirmed deal is a Trip File: payment progress plus an operations checklist built from a template. */
class TripPlan
{
    /** Share of the trip value that counts as "advance received" and confirms the trip automatically. */
    public static function advancePercent(): float
    {
        return (float) config('crm.trip.advance_percent', 25);
    }

    /** @return array{invoiced:float,paid:float,balance:float,percent:int,status:string,advance_received:bool,overdue:bool,next_due:?string,invoices:int} */
    public static function payment(Deal $deal): array
    {
        $inv = Invoice::query()->with('installments')->where('deal_id', $deal->id)->where('status', 'issued')->get();
        $invoiced = round((float) $inv->sum(fn ($i) => $i->total - $i->credited_total), 2);
        $paid = round((float) $inv->sum('paid_total'), 2);
        if ($inv->isEmpty()) {
            $invoiced = round((float) $deal->selling_amount, 2);
        }
        $balance = max(0.0, round($invoiced - $paid, 2));
        $percent = $invoiced > 0 ? (int) min(100, floor($paid / $invoiced * 100)) : 0;
        $status = $paid <= 0.005 ? 'not_paid' : ($balance <= 0.005 && $invoiced > 0 ? 'paid' : 'part_paid');
        $nextDue = $inv->filter(fn (Invoice $i) => $i->balance() > 0.005)->map(fn (Invoice $i) => $i->toApi()['next_due'] ?? null)->filter()->sort()->first();

        return ['invoiced' => $invoiced, 'paid' => $paid, 'balance' => $balance, 'percent' => $percent, 'status' => $status,
            'advance_received' => $invoiced > 0 && $percent >= self::advancePercent(),
            'overdue' => $inv->contains(fn (Invoice $i) => $i->paymentStatus() === 'overdue'),
            'next_due' => $nextDue, 'invoices' => $inv->count()];
    }

    /** @return array{total:int,done:int,percent:int,overdue:int,critical_open:int,next:?array{title:string,due_date:?string}} */
    public static function progress(Deal $deal): array
    {
        $tasks = TripTask::where('deal_id', $deal->id)->where('status', '<>', 'na')->orderBy('due_date')->orderBy('position')->get();
        $open = $tasks->filter(fn (TripTask $t) => $t->isOpen());
        $next = $open->first();
        $done = $tasks->where('status', 'done')->count();

        return ['total' => $tasks->count(), 'done' => $done, 'percent' => $tasks->isEmpty() ? 0 : (int) floor($done / $tasks->count() * 100),
            'overdue' => $open->filter(fn (TripTask $t) => $t->isOverdue())->count(), 'critical_open' => $open->where('critical', true)->count(),
            'next' => $next ? ['title' => $next->title, 'due_date' => $next->due_date?->format('Y-m-d')] : null];
    }

    /**
     * "Ready to travel": every critical item done, the balance paid and every required document verified.
     *
     * @return array{ready:bool,blockers:list<string>}
     */
    public static function readiness(array $payment, array $progress, array $docs): array
    {
        $b = [];
        if ($progress['total'] === 0) {
            $b[] = 'No checklist yet';
        }
        if ($progress['critical_open'] > 0) {
            $b[] = $progress['critical_open'].' critical item'.($progress['critical_open'] === 1 ? '' : 's').' open';
        }
        if ($payment['balance'] > 0.005) {
            $b[] = '₹'.number_format($payment['balance']).' balance unpaid';
        }
        if ($docs['travellers'] === 0) {
            $b[] = 'Travellers not chosen';
        } elseif (! $docs['all_verified']) {
            $n = max(0, $docs['required'] - $docs['verified']);
            $b[] = $n.' document'.($n === 1 ? '' : 's').' not verified';
        }

        return ['ready' => $b === [], 'blockers' => $b];
    }

    /** The trip type already on the deal, else domestic. The person confirming can always choose another. */
    public static function inferType(Deal $deal): string
    {
        return $deal->trip_type && in_array($deal->trip_type, TripTemplate::TYPES, true) ? $deal->trip_type : 'domestic';
    }

    public static function confirmed(Deal $deal): bool
    {
        return $deal->trip_confirmed_at !== null;
    }

    /** Create the plan (once). Returns the number of tasks created; 0 when the trip was already confirmed. */
    public static function confirm(Deal $deal, ?User $user, string $type, string $via): int
    {
        if (! in_array($type, TripTemplate::TYPES, true)) {
            $type = 'domestic';
        }
        if (self::confirmed($deal)) {
            return 0;
        }
        $tpl = TripTemplate::with('items')->where('trip_type', $type)->first();
        $travel = $deal->travel_date ? Carbon::parse($deal->travel_date->format('Y-m-d'), 'Asia/Kolkata') : null;
        $n = 0;
        DB::transaction(function () use ($deal, $user, $type, $tpl, $travel, &$n) {
            $deal->forceFill(['trip_type' => $type, 'trip_confirmed_at' => now(), 'trip_confirmed_by' => $user?->id, 'trip_status' => $deal->trip_status ?: 'confirmed'])->save();
            foreach ($tpl?->items ?? [] as $i) {
                TripTask::create([
                    'deal_id' => $deal->id, 'position' => $i->position, 'category' => $i->category, 'title' => $i->title, 'critical' => $i->critical,
                    'due_date' => $travel && $i->due_days_before !== null ? $travel->copy()->subDays((int) $i->due_days_before)->toDateString() : null,
                    'created_by' => $user?->id,
                ]);
                $n++;
            }
        });
        ActivityLogger::log($user, 'confirm_trip', 'deal', $deal->id, null, ['trip_type' => $type, 'via' => $via, 'tasks' => $n]);

        return $n;
    }

    /**
     * Undo a confirmation made by mistake: the checklist goes, the trip leaves the Trips board, and (when asked, and when we remember it)
     * the deal goes back to the stage it had before. Travellers and uploaded documents are kept. Returns how many checklist items were removed.
     */
    public static function revert(Deal $deal, ?User $user, string $reason, bool $restoreDeal): int
    {
        $prev = $deal->trip_confirm_prev;
        $n = 0;
        DB::transaction(function () use ($deal, $restoreDeal, $prev, &$n) {
            $n = TripTask::where('deal_id', $deal->id)->delete();
            $deal->trip_confirmed_at = null;
            $deal->trip_confirmed_by = null;
            if ($deal->trip_status === 'confirmed') {
                $deal->trip_status = null;
            }
            if ($restoreDeal && is_array($prev)) {
                $deal->stage = $prev['stage'] ?? $deal->stage;
                $deal->probability = $prev['probability'] ?? $deal->probability;
                $deal->closed_at = $prev['closed_at'] ?? null;
                $deal->lost_reason = $prev['lost_reason'] ?? null;
            }
            $deal->trip_confirm_prev = null;
            $deal->save();
        });
        ActivityLogger::log($user, 'revert_trip', 'deal', $deal->id, null, ['reason' => $reason, 'tasks_removed' => $n, 'deal_restored' => $restoreDeal && is_array($prev) ? ($prev['stage'] ?? null) : null]);

        return $n;
    }

    /** After a payment: once enough of the trip is paid, confirm it automatically. */
    public static function autoConfirm(Deal $deal, ?User $user): void
    {
        if (self::confirmed($deal) || $deal->stage !== 'won') {
            return;
        }
        if (self::payment($deal)['advance_received']) {
            self::confirm($deal, $user, self::inferType($deal), 'auto');
        }
    }

    /** If the travel date moves, open tasks move with it so their "N days before" offsets still hold. */
    public static function reschedule(Deal $deal, ?string $oldTravel): void
    {
        if (! self::confirmed($deal) || ! $deal->travel_date || ! $oldTravel) {
            return;
        }
        $days = (int) Carbon::parse($oldTravel)->diffInDays(Carbon::parse($deal->travel_date->format('Y-m-d')), false);
        if ($days === 0) {
            return;
        }
        TripTask::where('deal_id', $deal->id)->whereIn('status', ['pending', 'in_progress'])->whereNotNull('due_date')->get()
            ->each(fn (TripTask $t) => $t->forceFill(['due_date' => $t->due_date->copy()->addDays($days)->toDateString()])->save());
    }
}

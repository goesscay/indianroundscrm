<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\TripTask;
use App\Models\User;
use App\Services\TripDocRequests;
use App\Services\TripDocuments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily: (1) one "Trip prep" activity per person per trip for checklist items that are overdue or due within 2 days
 * (refreshed each day, completed when nothing is due); (2) when CRM_AUTO_TRIP_DOC_REMINDERS is on, an e-mail asking the
 * customer for missing documents 14, 7 and 3 days before travel.
 */
class TripsRemind extends Command
{
    protected $signature = 'trips:remind {--dry-run : show what would happen without creating or sending anything}';

    protected $description = 'Remind the team about due trip items and, optionally, customers about missing documents';

    public function handle(): int
    {
        $today = now('Asia/Kolkata')->startOfDay();
        $made = $done = $mails = 0;
        $trips = Deal::query()->with(['customer:id,name,email', 'owner:id,name'])->whereNotNull('trip_confirmed_at')
            ->where(fn ($w) => $w->whereNull('trip_status')->orWhere('trip_status', '<>', 'completed'))
            ->where(fn ($w) => $w->whereNull('travel_date')->orWhere('travel_date', '>=', $today->copy()->subDays(30)->toDateString()))->orderBy('id')->get();

        foreach ($trips as $deal) {
            // ---- the team
            $due = TripTask::with('assignee:id,name')->where('deal_id', $deal->id)->whereIn('status', ['pending', 'in_progress'])->whereNotNull('due_date')
                ->where('due_date', '<=', $today->copy()->addDays(2)->toDateString())->orderBy('due_date')->get();
            $owner = $deal->owner_id ? User::find($deal->owner_id) : null;
            $fallback = $owner && $owner->active && $owner->hasPermission('trips.manage') ? (int) $owner->id : null;
            $groups = $due->groupBy(fn (TripTask $t) => (string) ($t->assigned_to ?: $fallback ?: 0));
            $open = Activity::query()->where('deal_id', $deal->id)->whereNull('completed_at')->where('title', 'like', 'Trip prep:%')->get();
            $keep = [];
            foreach ($groups as $who => $tasks) {
                $assignee = (int) $who ?: null;
                $overdue = $tasks->contains(fn (TripTask $t) => $t->isOverdue());
                $title = 'Trip prep: '.$tasks->count().' item'.($tasks->count() === 1 ? '' : 's').' due — '.mb_substr(($deal->customer?->name ?? $deal->name), 0, 60).' · '.$deal->deal_no;
                $details = $tasks->map(fn (TripTask $t) => '• '.$t->title.' ('.($t->isOverdue() ? 'overdue since ' : 'due ').$t->due_date->format('d M').')'.($t->critical ? ' — critical' : ''))->implode("\n");
                $row = $open->first(fn ($a) => (int) $a->assigned_to === (int) $assignee);
                if ($row) {
                    $keep[] = $row->id;
                    if (! $this->option('dry-run')) {
                        $row->forceFill(['title' => $title, 'details' => $details, 'priority' => $overdue ? 'high' : 'medium'])->save();
                    }
                } else {
                    $made++;
                    if (! $this->option('dry-run')) {
                        $a = Activity::create(['type' => 'task', 'title' => $title, 'details' => $details, 'priority' => $overdue ? 'high' : 'medium', 'due_at' => now(),
                            'assigned_to' => $assignee, 'created_by' => null, 'deal_id' => $deal->id, 'customer_id' => $deal->customer_id, 'lead_id' => $deal->lead_id]);
                        $keep[] = $a->id;
                    }
                    $this->line("{$deal->deal_no}: $title");
                }
            }
            foreach ($open as $a) {
                if (! in_array($a->id, $keep, true) && ! $this->option('dry-run')) {
                    $a->forceFill(['completed_at' => now()])->save();
                    $done++;
                }
            }

            // ---- the customer
            if (config('crm.trip.auto_doc_reminders') && $deal->travel_date && TripDocuments::travellerIds($deal)) {
                $left = (int) $today->diffInDays($deal->travel_date->copy()->startOfDay(), false);
                $email = $deal->customer?->email;
                if (in_array($left, (array) config('crm.trip.doc_reminder_days'), true) && filter_var($email, FILTER_VALIDATE_EMAIL) && TripDocRequests::missing($deal)
                    && ! DB::table('trip_doc_reminders')->where('deal_id', $deal->id)->where('trigger', 'auto')->where('days_before', $left)->exists()) {
                    $this->line("{$deal->deal_no}: documents reminder to $email ($left days before)");
                    if (! $this->option('dry-run')) {
                        TripDocRequests::send($deal, [$email], null, max(7, $left), null, 'auto', $left);
                    }
                    $mails++;
                }
            }
        }
        $this->info(($this->option('dry-run') ? 'Would create ' : 'Created ')."$made trip-prep task(s), completed $done, ".($this->option('dry-run') ? 'would e-mail ' : 'e-mailed ')."$mails customer(s).");

        return self::SUCCESS;
    }
}

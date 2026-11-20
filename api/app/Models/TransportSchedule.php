<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;

/** A scheduled flight or train: details only, no price. */
class TransportSchedule extends Model
{
    use StringId;

    public const KINDS = ['flight', 'train'];
    public const DAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

    /** A schedule not touched for this many days is flagged "needs update" in Operations. */
    public const STALE_DAYS = 180;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['days' => 'array', 'active' => 'boolean', 'arrives_next_day' => 'boolean'];
    }

    /** "Daily", "Daily except Sun", "Mon, Wed, Fri". @param list<string> $days */
    public static function runsOn(array $days): string
    {
        $days = array_values(array_intersect(array_keys(self::DAYS), $days));
        if (count($days) === 7) {
            return 'Daily';
        }
        if (count($days) === 0) {
            return 'No days set';
        }
        if (count($days) >= 5) {
            $missing = array_values(array_diff(array_keys(self::DAYS), $days));

            return 'Daily except '.implode(', ', array_map(fn ($d) => self::DAYS[$d], $missing));
        }

        return implode(', ', array_map(fn ($d) => self::DAYS[$d], $days));
    }

    /** The weekday key (mon..sun) of a date like 2026-12-05. */
    public static function dayKey(string $date): ?string
    {
        $t = strtotime($date);

        return $t ? strtolower(substr(date('D', $t), 0, 3)) : null;
    }

    public function toApi(): array
    {
        $days = (array) $this->days;

        return [
            'id' => (string) $this->id, 'kind' => $this->kind, 'title' => $this->title, 'from_name' => $this->from_name, 'from_code' => $this->from_code,
            'to_name' => $this->to_name, 'to_code' => $this->to_code, 'serves' => $this->serves, 'departs' => $this->departs, 'arrives' => $this->arrives,
            'arrives_next_day' => (bool) $this->arrives_next_day, 'days' => $days, 'runs_on' => self::runsOn($days), 'classes' => $this->classes, 'note' => $this->note,
            'active' => (bool) $this->active, 'updated_at' => $this->updated_at?->utc()->toIso8601String(),
            'needs_update' => $this->updated_at !== null && $this->updated_at->lt(now()->subDays(self::STALE_DAYS)),
        ];
    }
}

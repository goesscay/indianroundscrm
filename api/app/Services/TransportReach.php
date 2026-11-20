<?php

namespace App\Services;

use App\Models\TransportSchedule;

/**
 * "Flights and trains" on a premium quotation: suggestions sales choose from the list (or type for this quote only) and the customer sees as
 * "How to reach". Details only. Nothing here is ever priced or added to a total, so the package price is the same with or without it.
 */
class TransportReach
{
    private static function text(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function time(mixed $v): ?string
    {
        $v = trim((string) $v);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
    }

    /** What the screen sent, made safe: the suggestions ticked on the quotation, each a copy of the details (so the quotation does not change if the list does). @return array{from:?string,items:list<array<string,mixed>>} */
    public static function clean(mixed $r): array
    {
        $r = (array) $r;
        $items = [];
        foreach (array_slice((array) ($r['items'] ?? []), 0, 12) as $x) {
            $x = (array) $x;
            $kind = (string) ($x['kind'] ?? '');
            $title = self::text($x['title'] ?? null, 200);
            $from = self::text($x['from_name'] ?? null, 120);
            $to = self::text($x['to_name'] ?? null, 120);
            if (! in_array($kind, TransportSchedule::KINDS, true) || ! $title || ! $from || ! $to) {
                continue;
            }
            $days = array_values(array_intersect(array_keys(TransportSchedule::DAYS), (array) ($x['days'] ?? [])));
            $items[] = [
                'schedule_id' => is_scalar($x['schedule_id'] ?? null) && ctype_digit((string) $x['schedule_id']) ? (int) $x['schedule_id'] : null,
                'kind' => $kind, 'direction' => ($x['direction'] ?? 'going') === 'return' ? 'return' : 'going', 'title' => $title, 'from_name' => $from, 'from_code' => self::text($x['from_code'] ?? null, 12), 'to_name' => $to, 'to_code' => self::text($x['to_code'] ?? null, 12),
                'departs' => self::time($x['departs'] ?? null), 'arrives' => self::time($x['arrives'] ?? null), 'arrives_next_day' => (bool) ($x['arrives_next_day'] ?? false),
                'days' => $days ?: array_keys(TransportSchedule::DAYS), 'classes' => self::text($x['classes'] ?? null, 120), 'note' => self::text($x['note'] ?? null, 600),
            ];
        }

        return ['from' => self::text($r['from'] ?? null, 120), 'items' => $items];
    }

    /**
     * The customer's "How to reach" block, or null when nothing is suggested. No price, no provider; airline and train names can be left out (general wording).
     *
     * @param  array<string,mixed>  $p  the cleaned proposal
     * @return array<string,mixed>|null
     */
    public static function build(array $p): ?array
    {
        $items = $p['reach']['items'] ?? [];
        if (! $items) {
            return null;
        }
        $named = ($p['transport']['wording'] ?? 'named') === 'named';
        $dest = $p['trip']['destination'] ?: 'your destination';
        usort($items, fn ($a, $b) => ($a['direction'] === 'return') <=> ($b['direction'] === 'return'));       // the way there first, then the way back
        $out = [];
        foreach ($items as $x) {
            $time = trim(($x['departs'] ?? '').($x['departs'] && $x['arrives'] ? ' → ' : '').($x['arrives'] ?? '').($x['arrives'] && $x['arrives_next_day'] ? ' (next day)' : ''));
            $out[] = [
                'kind' => $x['kind'], 'direction' => $x['direction'], 'label' => strtoupper($x['kind']), 'title' => $named ? $x['title'] : ucfirst($x['kind']),
                'route' => $x['from_name'].' → '.$x['to_name'], 'time' => $time, 'runs_on' => TransportSchedule::runsOn($x['days']), 'classes' => $x['classes'], 'note' => $x['note'],
            ];
        }

        return [
            'heading' => 'How to reach '.$dest.': suggestions', 'items' => $out,
            'disclaimer' => 'Please note: these are suggestions only and carry no price. Timings must be confirmed at the time of booking. Tell us your choice and we can help you book.',
        ];
    }
}

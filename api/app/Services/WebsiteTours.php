<?php

namespace App\Services;

use App\Models\PackageDay;
use App\Models\PackageDeparture;
use App\Models\TravelPackage;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Brings the tours of the public website (indianrounds.in) in as ERP packages, one way: website -> ERP.
 *
 *  - The website decides name, destination, length, summary, inclusions, itinerary, photo and (when it shows one) the adult price.
 *    Those fields are locked on an imported package until the team unlocks it.
 *  - The team keeps child price, supplier costs, GST, seasons, active/inactive and who can see the package.
 *  - A tour that disappears from the website is made inactive here, never deleted.
 *  - An unlocked package is never touched.
 */
class WebsiteTours
{
    private const PAGE = 20;

    public static function baseUrl(): string
    {
        return rtrim((string) config('crm.website.url', 'https://indianrounds.in'), '/');
    }

    /**
     * @return array{created:int,updated:int,unchanged:int,skipped_unlocked:int,deactivated:int,reactivated:int,failed:int,tours:int,departures:int}
     */
    public function run(): array
    {
        $listed = $this->listAll();
        $r = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_unlocked' => 0, 'deactivated' => 0, 'reactivated' => 0, 'failed' => 0, 'tours' => count($listed), 'departures' => 0];
        if (! $listed) {
            throw new \RuntimeException('The website returned no tours, so nothing was changed.');
        }

        $existing = TravelPackage::where('source', 'website')->get()->keyBy('source_ref');
        $failedSlugs = [];
        foreach (array_chunk(array_keys($listed), 8) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $slug) => $pool->as($slug)->timeout(20)->acceptJson()->get(self::baseUrl().'/api/v1/tours/'.rawurlencode($slug)),
                $chunk
            ));
            foreach ($chunk as $slug) {
                $res = $responses[$slug] ?? null;
                $tour = $res instanceof \Illuminate\Http\Client\Response && $res->ok() ? $res->json('data') : null;
                if (! is_array($tour)) {
                    $r['failed']++;
                    $failedSlugs[] = $slug;

                    continue;
                }
                $this->apply($existing->get($slug), $slug, $tour, $r);
            }
        }

        // tours that are gone from the website: hide them here (only when the list itself was read in full)
        foreach ($existing as $slug => $p) {
            if (isset($listed[$slug]) || in_array($slug, $failedSlugs, true) || $p->source_unlocked || $p->source_removed_at) {
                continue;
            }
            $p->forceFill(['active' => false, 'source_removed_at' => now()])->save();
            $r['deactivated']++;
        }

        return $r;
    }

    /** @return array<string,array> slug => the list entry */
    private function listAll(): array
    {
        $out = [];
        $page = 1;
        do {
            $res = Http::timeout(20)->retry(2, 400, throw: false)->acceptJson()->get(self::baseUrl().'/api/v1/tours', ['per_page' => self::PAGE, 'page' => $page]);
            if (! $res->ok()) {
                throw new \RuntimeException('The website did not answer (HTTP '.$res->status().').');
            }
            foreach ((array) $res->json('data') as $t) {
                if (! empty($t['slug'])) {
                    $out[(string) $t['slug']] = $t;
                }
            }
            $last = (int) $res->json('meta.last_page', 1);
            $page++;
        } while ($page <= $last && $page <= 100);

        return $out;
    }

    private function lines(mixed $v): string
    {
        return collect((array) $v)->map(fn ($x) => trim((string) $x))->filter()->implode("\n");
    }

    /** The package fields a website tour decides. */
    public function map(array $t): array
    {
        $base = self::baseUrl();
        $abs = fn (?string $u) => ! $u ? null : (str_starts_with($u, 'http') ? $u : $base.'/'.ltrim($u, '/'));
        $tags = collect((array) ($t['tags'] ?? []))->map(fn ($x) => mb_strtolower(is_array($x) ? ($x['name'] ?? '') : (string) $x))->implode(' ');
        $category = ($t['tour_type'] ?? '') === 'group' ? 'group'
            : (str_contains($tags, 'honeymoon') ? 'honeymoon'
            : (str_contains($tags, 'pilgrim') ? 'pilgrimage'
            : (str_contains($tags, 'adventure') ? 'adventure'
            : (($t['destination']['region'] ?? '') === 'international' ? 'international' : 'domestic'))));

        $terms = [];
        if (! empty($t['pickup']) || ! empty($t['drop_point'])) {
            $terms[] = trim(($t['pickup'] ? 'Pick-up: '.$t['pickup'] : '').($t['pickup'] && $t['drop_point'] ? '  |  ' : '').($t['drop_point'] ? 'Drop: '.$t['drop_point'] : ''));
        }
        if ($n = $this->lines($t['notes'] ?? [])) {
            $terms[] = "NOTES\n".$n;
        }
        if ($n = $this->lines($t['must_carry'] ?? [])) {
            $terms[] = "PLEASE CARRY\n".$n;
        }

        $days = [];
        foreach ((array) ($t['itinerary'] ?? []) as $d) {
            $title = trim((string) ($d['title'] ?? ''));
            if ($title !== '') {
                $days[] = ['title' => mb_substr($title, 0, 200), 'description' => trim((string) ($d['description'] ?? '')) ?: null];
            }
        }
        $departures = [];
        foreach ((array) ($t['batches'] ?? []) as $b) {
            if (! empty($b['id']) && ! empty($b['starts_on'])) {
                $departures[] = ['source_ref' => (string) $b['id'], 'start_date' => substr((string) $b['starts_on'], 0, 10), 'end_date' => ! empty($b['ends_on']) ? substr((string) $b['ends_on'], 0, 10) : null,
                    'seats_left' => isset($b['seats_left']) ? (int) $b['seats_left'] : null, 'status' => isset($b['status']) ? (string) $b['status'] : null];
            }
        }

        return [
            'fields' => [
                'name' => mb_substr(trim((string) ($t['title'] ?? 'Tour')), 0, 200),
                'destination' => ($t['destination']['name'] ?? null) ?: null,
                'category' => $category,
                'nights' => (int) ($t['duration_nights'] ?? 0), 'days' => (int) ($t['duration_days'] ?? 0),
                'summary' => trim((string) ($t['summary'] ?? '')) ?: (Str::limit(trim((string) ($t['overview'] ?? '')), 600, '') ?: null),
                'inclusions' => $this->lines($t['inclusions'] ?? []) ?: null, 'exclusions' => $this->lines($t['exclusions'] ?? []) ?: null,
                'terms' => $terms ? implode("\n\n", $terms) : null,
                'highlights' => $this->lines($t['highlights'] ?? []) ?: null,
                'image_url' => $abs($t['image_url'] ?? null),
                'website_url' => $base.'/tours/'.($t['slug'] ?? ''),
            ],
            'price' => isset($t['price']) && is_numeric($t['price']) ? (float) $t['price'] : null,
            'days_list' => $days,
            'departures' => $departures,
        ];
    }

    /** @param array<string,int> $r counters */
    private function apply(?TravelPackage $p, string $slug, array $tour, array &$r): void
    {
        $m = $this->map($tour);
        $hash = sha1(json_encode([$m['fields'], $m['price'], $m['days_list'], $m['departures']]));

        if ($p && $p->source_unlocked) {
            $r['skipped_unlocked']++;

            return;
        }
        if ($p && $p->source_hash === $hash && ! $p->source_removed_at) {
            $p->forceFill(['source_synced_at' => now()])->save();
            $r['unchanged']++;

            return;
        }

        DB::transaction(function () use ($p, $slug, $m, $hash, &$r) {
            $new = $p === null;
            $p ??= new TravelPackage(['code' => 'WEB-'.Str::limit($slug, 120, ''), 'source' => 'website', 'source_ref' => $slug, 'active' => true, 'gst_rate' => 5]);
            $p->fill($m['fields']);
            $p->source_price = $m['price'];
            if ($m['price'] !== null) {
                $p->adult_price = $m['price'];   // without a website price the team's own figure stays
            }
            if ($p->source_removed_at) {
                $p->active = true;               // it is back on the website
                $p->source_removed_at = null;
                $r['reactivated']++;
            }
            $p->source_hash = $hash;
            $p->source_synced_at = now();
            $p->save();

            $p->itineraryDays()->delete();
            foreach ($m['days_list'] as $i => $d) {
                PackageDay::create(['package_id' => $p->id, 'day_no' => $i + 1, 'title' => $d['title'], 'description' => $d['description']]);
            }
            $keep = [];
            foreach ($m['departures'] as $d) {
                PackageDeparture::updateOrCreate(['package_id' => $p->id, 'source_ref' => $d['source_ref']], collect($d)->except('source_ref')->all());
                $keep[] = $d['source_ref'];
                $r['departures']++;
            }
            PackageDeparture::where('package_id', $p->id)->whereNotIn('source_ref', $keep ?: ['-'])->delete();
            $new ? $r['created']++ : $r['updated']++;
        });
    }
}

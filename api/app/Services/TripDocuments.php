<?php

namespace App\Services;

use App\Models\CustomerTraveller;
use App\Models\Deal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** What a customer must hand over for a trip, who it is needed from, and how complete it is. */
class TripDocuments
{
    /**
     * key => [label, scope traveller|trip, mandatory for: 'all'|'international'|'child'|null (optional), only for international trips, tracks an expiry date]
     */
    public const CATALOG = [
        'id_proof' => ['ID proof (Aadhaar, voter ID or driving licence)', 'traveller', 'all', false, false],
        'age_proof' => ['Age proof for a child (birth certificate or school ID)', 'traveller', 'child', false, false],
        'passport' => ['Passport (photo page and address page)', 'traveller', 'international', true, true],
        'photo' => ['Passport-size photo', 'traveller', 'international', true, false],
        'visa' => ['Visa copy', 'traveller', 'international', true, true],
        'pan' => ['PAN card', 'traveller', null, true, false],
        'vaccination' => ['Vaccination certificate (if the destination needs one)', 'traveller', null, true, false],
        'insurance' => ['Travel insurance policy', 'trip', 'international', true, true],
        'terms' => ['Signed booking terms', 'trip', 'all', false, false],
        'payment_proof' => ['Payment proof', 'trip', null, false, false],
        'gst' => ['GST details (company bookings)', 'trip', null, false, false],
    ];

    public static function isInternational(Deal $deal): bool
    {
        return $deal->trip_type === 'international';
    }

    public static function tripEnd(Deal $deal): ?Carbon
    {
        if (! $deal->travel_date) {
            return null;
        }
        $d = Carbon::parse($deal->travel_date->format('Y-m-d'));

        return $deal->duration_days ? $d->addDays(max(0, $deal->duration_days - 1)) : $d;
    }

    /** child = under 12 on the travel date (or today when there is none); unknown date of birth counts as an adult */
    public static function isChild(CustomerTraveller $t, Deal $deal): bool
    {
        if (! $t->dob) {
            return false;
        }
        $on = $deal->travel_date ? Carbon::parse($deal->travel_date->format('Y-m-d')) : now();

        return $t->dob->diffInYears($on) < 12;
    }

    /** @return array{label:string,scope:string,mandatory:bool,expiry:bool} or null when the document does not apply */
    public static function requirement(string $key, ?CustomerTraveller $t, Deal $deal): ?array
    {
        [$label, $scope, $mand, $intlOnly, $expiry] = self::CATALOG[$key];
        $intl = self::isInternational($deal);
        if ($intlOnly && ! $intl) {
            return null;
        }
        $child = $t ? self::isChild($t, $deal) : false;
        if ($key === 'age_proof' && ! $child) {
            return null;
        }
        $mandatory = $mand === 'all' || ($mand === 'international' && $intl) || ($mand === 'child' && $child);

        return ['label' => $label, 'scope' => $scope, 'mandatory' => $mandatory, 'expiry' => $expiry];
    }

    /** @return list<int> */
    public static function travellerIds(Deal $deal): array
    {
        return DB::table('trip_travellers')->where('deal_id', $deal->id)->pluck('traveller_id')->map(fn ($i) => (int) $i)->all();
    }

    /** Make sure a row exists for every document the trip needs; drop rows that no longer apply and hold nothing. */
    public static function ensureSlots(Deal $deal): void
    {
        $travellers = CustomerTraveller::whereIn('id', self::travellerIds($deal))->get();
        $want = [];
        foreach (self::CATALOG as $key => $def) {
            if ($def[1] === 'trip') {
                if (self::requirement($key, null, $deal)) {
                    $want[] = [null, $key];
                }
            } else {
                foreach ($travellers as $t) {
                    if (self::requirement($key, $t, $deal)) {
                        $want[] = [(int) $t->id, $key];
                    }
                }
            }
        }
        $have = DB::table('trip_documents')->where('deal_id', $deal->id)->get(['id', 'traveller_id', 'doc_type', 'status']);
        $haveKeys = $have->map(fn ($r) => ($r->traveller_id ?? 0).'|'.$r->doc_type)->all();
        foreach ($want as [$tid, $key]) {
            if (! in_array(($tid ?? 0).'|'.$key, $haveKeys, true)) {
                DB::table('trip_documents')->insert(['deal_id' => $deal->id, 'traveller_id' => $tid, 'doc_type' => $key, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $wantKeys = array_map(fn ($w) => ($w[0] ?? 0).'|'.$w[1], $want);
        foreach ($have as $r) {
            $hasFiles = DB::table('trip_document_files')->where('document_id', $r->id)->exists();
            if (! in_array(($r->traveller_id ?? 0).'|'.$r->doc_type, $wantKeys, true) && ! $hasFiles && $r->status === 'pending') {
                DB::table('trip_documents')->where('id', $r->id)->delete();
            }
        }
    }

    /** ok | short (valid, but under 6 months beyond the return date) | expired | unknown */
    public static function passportValidity(CustomerTraveller $t, Deal $deal): string
    {
        if (! $t->passport_expiry) {
            return 'unknown';
        }
        $end = self::tripEnd($deal);
        if (! $end) {
            return $t->passport_expiry->lt(now()) ? 'expired' : 'ok';
        }
        if ($t->passport_expiry->lt($end)) {
            return 'expired';
        }

        return $t->passport_expiry->lt($end->copy()->addMonths(6)) ? 'short' : 'ok';
    }

    /** Counts over the document rows that exist now (the Documents panel creates them as travellers are chosen). @return array{required:int,received:int,verified:int,complete:bool,all_verified:bool,missing:int,travellers:int} */
    public static function summary(Deal $deal): array
    {
        if (! self::travellerIds($deal)) {
            return ['required' => 0, 'received' => 0, 'verified' => 0, 'complete' => false, 'all_verified' => false, 'missing' => 0, 'travellers' => 0];
        }
        $rows = DB::table('trip_documents')->where('deal_id', $deal->id)->get(['traveller_id', 'doc_type', 'status']);
        $travellers = CustomerTraveller::whereIn('id', self::travellerIds($deal))->get()->keyBy('id');
        $req = $rec = $ver = 0;
        foreach ($rows as $r) {
            $mand = self::requirement($r->doc_type, $r->traveller_id ? $travellers->get($r->traveller_id) : null, $deal)['mandatory'] ?? false;
            if (! $mand || $r->status === 'na') {
                continue;
            }
            $req++;
            if (in_array($r->status, ['received', 'verified'], true)) {
                $rec++;
            }
            if ($r->status === 'verified') {
                $ver++;
            }
        }

        return ['required' => $req, 'received' => $rec, 'verified' => $ver, 'complete' => $req > 0 && $rec === $req, 'all_verified' => $req > 0 && $ver === $req,
            'missing' => $req - $rec, 'travellers' => count($travellers)];
    }

    /** Once every required document is verified the trip moves from Confirmed to Documents collected. */
    public static function syncTripStatus(Deal $deal): void
    {
        if ($deal->trip_status === 'confirmed' && self::summary($deal)['all_verified']) {
            $deal->forceFill(['trip_status' => 'docs_collected'])->save();
        }
    }
}

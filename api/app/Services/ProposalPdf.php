<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\HotelRate;
use App\Models\HotelRoom;
use App\Models\Media;
use App\Models\Quote;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;

/** The premium pictorial quotation: cover, trip at a glance, a page of pictures per day, the hotels, the price per person for each group size, policies and a closing page. */
class ProposalPdf
{
    /** @var array<string,string> */
    private static array $cache = [];

    /** A picture cropped to fill w x h (like CSS cover), as a JPEG data URI; dompdf cannot crop, so it is done here. */
    public static function image(?Media $m, int $w, int $h, int $quality = 78): ?string
    {
        if (! $m) {
            return null;
        }
        $key = "$m->id:$w:$h";
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $bytes = MediaStore::bytes($m);
        $src = $bytes ? @imagecreatefromstring($bytes) : false;
        if (! $src) {
            return null;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        $ratio = $w / $h;
        if ($sw / $sh > $ratio) {
            $cw = (int) round($sh * $ratio);
            $cx = (int) (($sw - $cw) / 2);
            $cy = 0;
            $ch = $sh;
        } else {
            $cw = $sw;
            $ch = (int) round($sw / $ratio);
            $cx = 0;
            $cy = (int) (($sh - $ch) / 3);              // keep the upper part: skies and mountains are the subject
        }
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $w, $h, $cw, $ch);
        ob_start();
        imagejpeg($dst, null, $quality);

        return self::$cache[$key] = 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    private static function logo(): ?string
    {
        $f = resource_path('brand/indian-rounds-logo.png');

        return is_file($f) ? 'data:image/png;base64,'.base64_encode(file_get_contents($f)) : null;
    }

    /** @return array<string,mixed> everything the view needs */
    public static function data(Quote $quote): array
    {
        $quote->loadMissing(['customer', 'lines', 'owner']);
        $p = ProposalPricing::clean((array) $quote->proposal);
        $computed = ProposalPricing::compute($p, false);
        $trip = $p['trip'];
        $start = $trip['start_date'] ? Carbon::parse($trip['start_date']) : null;

        $mediaIds = array_filter(array_merge([$p['cover_media_id']], array_column($p['days'], 'media_id')));
        $mediaById = $mediaIds ? Media::whereIn('id', $mediaIds)->get()->keyBy(fn ($m) => (string) $m->id) : collect();

        $days = [];
        foreach ($p['days'] as $i => $d) {
            $m = $d['media_id'] ? $mediaById->get((string) $d['media_id']) : null;
            $days[] = $d + ['no' => $i + 1, 'date' => $start && $i <= $trip['nights'] ? $start->copy()->addDays($i) : null, 'img' => self::image($m, 520, 340)];
        }

        $options = [];
        foreach ($p['options'] as $oi => $o) {
            $stays = [];
            foreach ($o['stays'] as $s) {
                $room = HotelRoom::find($s['room_id']);
                $hotel = Hotel::with('photos')->find($s['hotel_id']);
                if (! $hotel || ! $room || (int) $room->hotel_id !== (int) $hotel->id) {
                    continue;
                }
                $photos = $hotel->photos->values();
                $cin = $start ? $start->copy()->addDays($s['start_offset']) : null;
                $stays[] = [
                    'hotel' => $hotel, 'room' => $room->name, 'meal' => HotelRate::MEALS[$s['meal_plan']] ?? $s['meal_plan'], 'nights' => $s['nights'],
                    'check_in' => $cin, 'check_out' => $cin?->copy()->addDays($s['nights']),
                    'main' => self::image($photos->get(0), 640, 440), 'gallery' => $photos->slice(1, 3)->map(fn ($x) => self::image($x, 420, 280))->filter()->values()->all(),
                ];
            }
            $options[] = ['name' => $o['name'], 'stays' => $stays, 'prices' => $computed['options'][$oi] ?? null, 'selected' => $oi === $p['selected_option']];
        }

        return [
            'quote' => $quote, 'p' => $p, 'trip' => $trip, 'start' => $start, 'days' => $days, 'options' => $options, 'sizes' => $trip['group_sizes'],
            'cover' => self::image($p['cover_media_id'] ? $mediaById->get((string) $p['cover_media_id']) : null, 1190, 1060, 82),
            'logo' => self::logo(), 'company' => config('crm.company'), 'sign' => config('crm.signature'),
        ];
    }

    public static function render(Quote $quote): string
    {
        $opts = new Options();
        $opts->set('defaultFont', 'DejaVu Sans');
        $opts->set('isRemoteEnabled', false);
        $opts->set('isHtml5ParserEnabled', true);
        $opts->set('chroot', base_path());
        $opts->set('tempDir', storage_path('app'));
        $opts->set('fontCache', storage_path('app'));

        $pdf = new Dompdf($opts);
        $pdf->loadHtml(view('pdf.proposal', self::data($quote))->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\HotelRate;
use App\Models\HotelRoom;
use App\Models\Media;
use App\Services\MediaStore;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/** Demo hotels, rooms, seasonal rates and Unsplash pictures so the premium quotation can be tried. Everything is named "(Demo)" so it is easy to remove. */
class DemoQuotation extends Command
{
    protected $signature = 'demo:quotation {dir : folder with the downloaded pictures} {--remove : delete the demo hotels and pictures instead}';

    protected $description = 'Add (or remove) demo hotels, rates and pictures for trying the premium quotation';

    /** destination => [season name, start, end, price factor] */
    private const SEASONS = [
        'Manali' => [['Winter', '2026-11-01', '2026-12-19', 1.0], ['Peak', '2026-12-20', '2027-01-10', 1.7], ['Winter', '2027-01-11', '2027-03-31', 1.0], ['Summer', '2027-04-01', '2027-06-30', 1.35]],
        'Goa' => [['Peak', '2026-11-01', '2027-02-28', 1.4], ['Shoulder', '2027-03-01', '2027-05-31', 0.9], ['Monsoon', '2027-06-01', '2027-06-30', 0.65]],
        'Munnar' => [['Season', '2026-11-01', '2027-03-31', 1.2], ['Summer', '2027-04-01', '2027-06-30', 1.0]],
    ];

    /** @return list<array<string,mixed>> */
    private function hotels(): array
    {
        return [
            ['name' => 'The Pine Crest Manali (Demo)', 'destination' => 'Manali', 'city' => 'Old Manali', 'stars' => 3, 'rating' => 4.2, 'reviews' => 640, 'phone' => '+91 98160 11001', 'address' => 'Old Manali Road, near Manu Temple, Manali, Himachal Pradesh',
                'amenities' => ['Wi-Fi', 'Room heater', 'Hot water', 'Restaurant', 'Parking'], 'desc' => 'A cosy family run hotel in Old Manali with mountain views.',
                'rooms' => [['Deluxe Room with Valley View', 2, 3200], ['Family Room', 3, 4300]], 'photos' => ['1611892440504-42a792e24d32', '1618773928121-c32242e63f39', '1759328257280-28bdb3050be3', '1782890790046-2aefb0b503cb']],
            ['name' => 'Snow Valley Resort Manali (Demo)', 'destination' => 'Manali', 'city' => 'Mall Road', 'stars' => 4, 'rating' => 4.4, 'reviews' => 1210, 'phone' => '+91 98160 11002', 'address' => 'Hadimba Road, Manali, Himachal Pradesh',
                'amenities' => ['Wi-Fi', 'Room heater', 'Swimming pool', 'Spa', 'Restaurant', 'Bonfire'], 'desc' => 'A 4 star resort close to Mall Road with a heated pool and spa.',
                'rooms' => [['Premium Room', 2, 5200], ['Premium Room with Balcony', 2, 6100]], 'photos' => ['1631049307264-da0ec9d70304', '1765547090903-348b711f0eee', '1778205015308-1ed324aea202', '1610641818989-c2051b5e2cfd']],
            ['name' => 'Himalayan Heights Manali (Demo)', 'destination' => 'Manali', 'city' => 'Naggar Road', 'stars' => 5, 'rating' => 4.6, 'reviews' => 980, 'phone' => '+91 98160 11003', 'address' => 'Naggar Road, Prini, Manali, Himachal Pradesh',
                'amenities' => ['Wi-Fi', 'Central heating', 'Swimming pool', 'Spa', 'Fine dining', 'Airport transfer'], 'desc' => 'A luxury stay with panoramic snow peak views and central heating.',
                'rooms' => [['Luxury Room', 2, 8800], ['Luxury Suite', 3, 12500]], 'photos' => ['1781572725438-ac8c41476752', '1778205015308-1ed324aea202', '1757924382007-7b3228526d09', '1551882547-ff40c63fe5fa']],
            ['name' => 'Palm Bay Beach Resort (Demo)', 'destination' => 'Goa', 'city' => 'Calangute', 'stars' => 4, 'rating' => 4.3, 'reviews' => 2140, 'phone' => '+91 98220 22001', 'address' => 'Candolim Beach Road, Calangute, Goa',
                'amenities' => ['Wi-Fi', 'Air conditioning', 'Swimming pool', 'Beach access', 'Restaurant', 'Bar'], 'desc' => 'A beachside resort a short walk from the sand, with a large pool.',
                'rooms' => [['Superior Room', 2, 4600], ['Pool View Room', 2, 5600]], 'photos' => ['1549294413-26f195200c16', '1623718649591-311775a30c43', '1618773928121-c32242e63f39', '1757924382007-7b3228526d09']],
            ['name' => 'Sunset Shores Goa (Demo)', 'destination' => 'Goa', 'city' => 'Baga', 'stars' => 3, 'rating' => 4.0, 'reviews' => 870, 'phone' => '+91 98220 22002', 'address' => 'Baga Beach Road, Baga, Goa',
                'amenities' => ['Wi-Fi', 'Air conditioning', 'Swimming pool', 'Restaurant'], 'desc' => 'A simple, well located stay close to the Baga nightlife and beach.',
                'rooms' => [['Standard Room', 2, 2600], ['Deluxe Room', 3, 3400]], 'photos' => ['1551882547-ff40c63fe5fa', '1629813538702-64c925934e19', '1759328257280-28bdb3050be3']],
            ['name' => 'Tea Valley Retreat Munnar (Demo)', 'destination' => 'Munnar', 'city' => 'Chithirapuram', 'stars' => 4, 'rating' => 4.5, 'reviews' => 760, 'phone' => '+91 98470 33001', 'address' => 'Tea Estate Road, Chithirapuram, Munnar, Kerala',
                'amenities' => ['Wi-Fi', 'Room heater', 'Ayurvedic spa', 'Restaurant', 'Plantation walk'], 'desc' => 'A retreat on a working tea estate with valley views from every room.',
                'rooms' => [['Valley View Room', 2, 4800], ['Cottage', 3, 6400]], 'photos' => ['1611892440504-42a792e24d32', '1765547090903-348b711f0eee', '1631049307264-da0ec9d70304', '1782890790046-2aefb0b503cb']],
        ];
    }

    /** @return array<string,list<array{0:string,1:string}>> destination => [file, caption] */
    private function destinationPictures(): array
    {
        return [
            'Manali' => [['1712388430474-ace0c16051e2', 'Snow peaks above the Beas valley'], ['1773739967497-e09144a822d4', 'Deodar pines and snow peaks'], ['1597167231350-d057a45dc868', 'Manali town below the snow line'],
                ['1605649487212-47bdab064df7', 'The Pir Panjal range'], ['1593181629936-11c609b8db9b', 'Snow fun at Solang'], ['1579689189009-874f5cac2db5', 'Playing in the snow'], ['1626621341517-bbf3d9990a23', 'A winter trek'], ['1609920658906-8223bd289001', 'The Beas river']],
            'Goa' => [['1512343879784-a960bf40e7f2', 'Turquoise water and palm shores'], ['1614082242765-7c98ca0f3df3', 'Beach huts and palms'], ['1560179406-1c6c60e0dc76', 'Beach umbrellas'], ['1582972236019-ea4af5ffe587', 'A quiet Goan beach'], ['1757702244726-00198554c4a0', 'Green hills meet the sea'], ['1496566084516-c5b96fcbd5c8', 'Swimming off the coast']],
            'Munnar' => [['1491497895121-1334fc14d8c9', 'Misty tea gardens'], ['1637066742971-726bee8d9f56', 'A stream through the tea hills'], ['1629813538702-64c925934e19', 'Tea hills at sunset'], ['1766671965039-8bc8687390ad', 'Misty lake at sunrise']],
        ];
    }

    private function upload(string $dir, string $key, array $attrs): ?Media
    {
        $f = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$key.'.jpg';
        if (! is_file($f)) {
            $this->warn("missing picture $key");

            return null;
        }

        return MediaStore::store(new UploadedFile($f, "$key.jpg", 'image/jpeg', null, true), $attrs, null);
    }

    public function handle(): int
    {
        if ($this->option('remove')) {
            foreach (Hotel::where('name', 'like', '%(Demo)')->get() as $h) {
                $h->photos->each(fn ($m) => MediaStore::delete($m));
                $h->delete();
            }
            Media::whereNull('hotel_id')->where('original_name', 'like', 'demo-%')->get()->each(fn ($m) => MediaStore::delete($m));
            $this->info('Demo hotels, rates and pictures removed.');

            return self::SUCCESS;
        }
        $dir = (string) $this->argument('dir');

        foreach ($this->destinationPictures() as $dest => $pics) {
            if (Media::whereNull('hotel_id')->where('destination', $dest)->where('original_name', 'like', 'demo-%')->exists()) {
                continue;
            }
            foreach ($pics as $i => [$key, $caption]) {
                $m = $this->upload($dir, $key, ['destination' => $dest, 'kind' => $i === 0 ? 'cover' : 'day', 'caption' => $caption, 'position' => $i]);
                $m?->update(['original_name' => "demo-$key.jpg"]);
            }
            $this->line("pictures for $dest added");
        }

        foreach ($this->hotels() as $d) {
            if (Hotel::where('name', $d['name'])->exists()) {
                $this->line("{$d['name']}: already there");
                continue;
            }
            $h = Hotel::create(['name' => $d['name'], 'destination' => $d['destination'], 'city' => $d['city'], 'stars' => $d['stars'], 'address' => $d['address'], 'phone' => $d['phone'],
                'google_rating' => $d['rating'], 'google_reviews' => $d['reviews'], 'description' => $d['desc'], 'amenities' => $d['amenities'], 'active' => true]);
            foreach ($d['photos'] as $i => $key) {
                $this->upload($dir, $key, ['hotel_id' => $h->id, 'destination' => $d['destination'], 'kind' => 'hotel', 'caption' => $d['name'], 'position' => $i, 'is_cover' => $i === 0]);
            }
            foreach ($d['rooms'] as [$name, $adults, $base]) {
                $room = HotelRoom::create(['hotel_id' => $h->id, 'name' => $name, 'max_adults' => $adults, 'active' => true]);
                foreach (self::SEASONS[$d['destination']] as [$season, $from, $to, $f]) {
                    foreach (['CP' => 0, 'MAP' => 900] as $meal => $add) {
                        $double = (int) (round(($base * $f + $add) / 50) * 50);
                        HotelRate::create(['room_id' => $room->id, 'meal_plan' => $meal, 'season' => $season, 'start_date' => $from, 'end_date' => $to,
                            'single' => (int) (round($double * 0.8 / 50) * 50), 'double' => $double, 'triple' => (int) (round($double * 1.3 / 50) * 50),
                            'extra_adult' => (int) (round($double * 0.35 / 50) * 50), 'child_with_bed' => (int) (round($double * 0.3 / 50) * 50), 'child_no_bed' => (int) (round($double * 0.15 / 50) * 50)]);
                    }
                }
            }
            $this->info("{$d['name']}: added");
        }

        return self::SUCCESS;
    }
}

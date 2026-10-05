<?php

namespace Database\Seeders;

use App\Models\DestinationCity;
use App\Models\Master;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Port of seed_admin (auth.py) and seed_master (server.py). Idempotent. */
class CoreSeeder extends Seeder
{
    private const DEFAULT_MASTER = [
        'destination' => ['Bali', 'Dubai', 'Maldives', 'Thailand', 'Singapore', 'Kashmir', 'Kerala', 'Goa', 'Andaman'],
        'hotel_category' => ['3 Star', '4 Star', '5 Star', 'Villa / Resort', 'Boutique'],
        'meal_plan' => ['CP', 'MAP', 'AP', 'EP'],
        'package_type' => ['Solo', 'Couple', 'Family', 'Honeymoon', 'Group'],
        'room_type' => ['Single', 'Double', 'Twin', 'Triple', 'Suite'],
        'departure_city' => ['Mumbai', 'Delhi', 'Bangalore', 'Chennai', 'Kolkata', 'Hyderabad'],
        'payment_mode' => ['Bank Transfer', 'UPI', 'Card', 'Cash'],
        'lead_source' => ['Website', 'Referral', 'Instagram', 'Facebook', 'Walk-in', 'WhatsApp'],
    ];

    private const DEFAULT_SUPPLIERS = [
        ['name' => 'Wanderlust Holidays', 'email' => 'sales@wanderlust.demo', 'phone' => '+91-9876543210', 'destination' => 'Bali', 'country' => 'Indonesia'],
        ['name' => 'SunSand Tours', 'email' => 'quotes@sunsand.demo', 'phone' => '+91-9876543211', 'destination' => 'Maldives', 'country' => 'Maldives'],
        ['name' => 'Emirates Travel Partners', 'email' => 'b2b@emiratestravel.demo', 'phone' => '+971-501234567', 'destination' => 'Dubai', 'country' => 'UAE'],
        ['name' => 'Andaman Blue', 'email' => 'hello@andamanblue.demo', 'phone' => '+91-9876543212', 'destination' => 'Andaman', 'country' => 'India'],
    ];

    private const DEFAULT_DESTINATION_CITIES = [
        'Thailand' => ['Bangkok', 'Chiang Mai', 'Chiang Rai', 'Ayutthaya', 'Sukhothai', 'Phuket', 'Krabi', 'Ko Samui', 'Pattaya', 'Hua Hin'],
        'Bali' => ['Kuta', 'Seminyak', 'Ubud', 'Nusa Dua', 'Uluwatu', 'Sanur', 'Canggu', 'Nusa Penida'],
        'Dubai' => ['Downtown Dubai', 'Dubai Marina', 'Palm Jumeirah', 'Jumeirah', 'Deira', 'Business Bay', 'JBR'],
        'Singapore' => ['Marina Bay', 'Sentosa', 'Orchard', 'Chinatown', 'Little India', 'Clarke Quay'],
        'Malaysia' => ['Kuala Lumpur', 'Langkawi', 'Penang', 'Kota Kinabalu', 'Malacca', 'Genting Highlands'],
        'Sri Lanka' => ['Colombo', 'Kandy', 'Nuwara Eliya', 'Bentota', 'Galle', 'Ella', 'Sigiriya', 'Yala'],
        'Vietnam' => ['Ho Chi Minh City', 'Hanoi', 'Halong Bay', 'Da Nang', 'Hoi An', 'Nha Trang', 'Phu Quoc'],
        'Maldives' => ['Male', 'North Male Atoll', 'South Male Atoll', 'Ari Atoll', 'Baa Atoll'],
        'Europe' => ['Paris', 'London', 'Amsterdam', 'Zurich', 'Rome', 'Venice', 'Barcelona', 'Prague', 'Vienna', 'Interlaken'],
        'Kashmir' => ['Srinagar', 'Gulmarg', 'Pahalgam', 'Sonmarg', 'Leh', 'Nubra Valley'],
        'Kerala' => ['Kochi', 'Munnar', 'Thekkady', 'Alleppey', 'Kumarakom', 'Kovalam', 'Wayanad'],
        'Goa' => ['North Goa', 'South Goa', 'Panaji', 'Calangute', 'Baga', 'Palolem', 'Anjuna'],
        'Andaman' => ['Port Blair', 'Havelock', 'Neil Island', 'Ross Island', 'Baratang'],
        'Rajasthan' => ['Jaipur', 'Udaipur', 'Jodhpur', 'Jaisalmer', 'Pushkar', 'Mount Abu', 'Bikaner'],
        'Himachal' => ['Shimla', 'Manali', 'Dharamshala', 'McLeodganj', 'Kasol', 'Spiti', 'Dalhousie', 'Kufri'],
        'Uttarakhand' => ['Rishikesh', 'Haridwar', 'Mussoorie', 'Nainital', 'Auli', 'Jim Corbett', 'Kedarnath', 'Badrinath'],
    ];

    public function run(): void
    {
        $this->seedUsers();
        $this->seedMaster();
    }

    private function seedUsers(): void
    {
        // Production: only the env-configured admin is created. No built-in owner, no demo accounts.
        if (app()->isProduction()) {
            $email = strtolower((string) env('ADMIN_EMAIL'));
            $password = (string) env('ADMIN_PASSWORD');
            if ($email === '' || $password === '') {
                throw new \RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD before seeding in production.');
            }
            if (! User::where('email', $email)->exists()) {
                User::create(['email' => $email, 'password' => $password, 'name' => 'Admin', 'role' => 'admin', 'staff_code' => 'ADM001']);
            }

            return;
        }

        // OWNER - real developer account, always admin + active.
        $owner = User::where('email', 'omnamasiviya73@gmail.com')->first();
        if (! $owner) {
            User::create([
                'email' => 'omnamasiviya73@gmail.com', 'password' => 'Ir@2026Owner', 'name' => 'Owner',
                'role' => 'admin', 'staff_code' => 'OWN001', 'consultant_code' => 'OW',
            ]);
        } else {
            $owner->update(['role' => 'admin', 'active' => true]);
        }

        $email = strtolower(env('ADMIN_EMAIL', 'admin@indianrounds.com'));
        $password = env('ADMIN_PASSWORD', 'admin123');
        $admin = User::where('email', $email)->first();
        if (! $admin) {
            User::create(['email' => $email, 'password' => $password, 'name' => 'Admin', 'role' => 'admin', 'staff_code' => 'ADM001']);
        } elseif (! Hash::check($password, $admin->password)) {
            $admin->update(['password' => $password]);
        }

        $demos = [
            ['email' => 'sales@indianrounds.com', 'password' => 'Ir@2026Safe', 'name' => 'Ravi Kumar', 'role' => 'sales', 'staff_code' => 'SAL001', 'consultant_code' => 'RK'],
            ['email' => 'ops@indianrounds.com', 'password' => 'ops123', 'name' => 'Priya Sharma', 'role' => 'operations', 'staff_code' => 'OPS001', 'consultant_code' => ''],
        ];
        foreach ($demos as $d) {
            if (! User::where('email', $d['email'])->exists()) {
                User::create($d);
            }
        }
    }

    private function seedMaster(): void
    {
        foreach (self::DEFAULT_MASTER as $category => $values) {
            foreach ($values as $v) {
                Master::firstOrCreate(['category' => $category, 'value' => $v]);
            }
        }
        if (! app()->isProduction()) {
            foreach (self::DEFAULT_SUPPLIERS as $s) {
                Supplier::firstOrCreate(['email' => $s['email']], $s);
            }
        }
        foreach (self::DEFAULT_DESTINATION_CITIES as $dest => $cities) {
            $dc = DestinationCity::firstOrCreate(['destination' => $dest]);
            if ($dc->wasRecentlyCreated) {
                $dc->replaceCities($cities);
            }
            Master::firstOrCreate(['category' => 'destination', 'value' => $dest]);
        }
    }
}

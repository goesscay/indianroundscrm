<?php

namespace App\Console\Commands;

use App\Http\Controllers\Crm\AccountController;
use App\Http\Controllers\Crm\CustomerController;
use App\Http\Controllers\Crm\DealController;
use App\Http\Controllers\Crm\ForecastController;
use App\Http\Controllers\Crm\InvoiceController;
use App\Http\Controllers\Crm\LeadConvertController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\PackageController;
use App\Http\Controllers\Crm\PayablesController;
use App\Http\Controllers\Crm\QuoteController;
use App\Http\Controllers\Crm\ReceiptController;
use App\Models\Activity;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\TravelPackage;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loads a realistic demo company into an ISOLATED schema (DB_SEARCH_PATH=demo) so your development data is never touched.
 * Everything goes through the same controllers the app uses, so invoices, receipts, bills and the ledger agree with each other.
 *
 *   DB_SEARCH_PATH=demo php artisan demo:load
 */
class DemoLoad extends Command
{
    /** Throw-away password for every demo account (local demo only). */
    public const PASSWORD = 'Demo@2026#Travel';

    protected $signature = 'demo:load {--keep : do not wipe the demo schema first}';

    protected $description = 'Wipe the isolated demo schema and load realistic data for every module';

    private Carbon $today;

    /** @var array<string,User> */
    private array $u = [];

    private function d(int $days): string
    {
        return $this->today->copy()->addDays($days)->toDateString();
    }

    /** Call a controller action as a user, the way a request would. */
    private function api(string $class, string $method, User $user, array $body = [], array $args = []): array
    {
        $req = Request::create('/', 'POST', [], [], [], [], json_encode($body));
        $req->setUserResolver(fn () => $user);
        try {
            $res = app($class)->{$method}($req, ...$args);
        } catch (HttpResponseException $e) {
            $r = $e->getResponse();
            throw new \RuntimeException("$class::$method failed (".$r->getStatusCode().'): '.($r instanceof JsonResponse ? json_encode($r->getData(true)) : $r->getContent()));
        }
        if ($res instanceof JsonResponse) {
            return $res->getData(true);
        }

        return is_array($res) ? $res : (method_exists($res, 'toArray') ? $res->toArray() : (array) $res);
    }

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('demo:load only runs in the local environment.');

            return self::FAILURE;
        }
        if (config('database.connections.pgsql.search_path') !== 'demo') {
            $this->error('Refusing to run: start it with DB_SEARCH_PATH=demo so your development data is not touched.');

            return self::FAILURE;
        }
        $this->today = Carbon::now('Asia/Kolkata')->startOfDay();
        mt_srand(2026);
        config(['mail.default' => 'log']);

        DB::statement('CREATE SCHEMA IF NOT EXISTS demo');
        $publicBefore = (int) DB::scalar("select count(*) from information_schema.tables where table_schema='public'");
        if (! $this->option('keep')) {
            $this->info('Resetting the demo schema…');
            $this->call('migrate:fresh', ['--force' => true]);
        }
        $publicAfter = (int) DB::scalar("select count(*) from information_schema.tables where table_schema='public'");
        if ($publicBefore !== $publicAfter) {
            $this->error('The public schema changed. Stopping.');

            return self::FAILURE;
        }

        DB::transaction(function () {
            $this->step('Users and master data', fn () => $this->users());
            $this->step('Bank accounts', fn () => $this->banks());
            $this->step('Packages', fn () => $this->packages());
            $this->step('Leads, notes and supplier mail', fn () => $this->leads());
            $this->step('Customers and deals', fn () => $this->customersAndDeals());
            $this->step('Quotes', fn () => $this->quotes());
            $this->step('Invoices, receipts and credit notes', fn () => $this->invoices());
            $this->step('Supplier bills, expenses and forecasts', fn () => $this->payables());
            $this->step('Activities, notes and chat', fn () => $this->activitiesAndChat());
        });

        $this->newLine();
        $this->table(['Role', 'Name', 'E-mail'], collect($this->u)->map(fn (User $x) => [$x->role, $x->name, $x->email])->values()->all());
        $this->info('Every demo account uses the password defined as DemoLoad::PASSWORD in app/Console/Commands/DemoLoad.php.');

        return self::SUCCESS;
    }

    private function step(string $label, callable $fn): void
    {
        $this->line("• $label");
        $fn();
    }

    // ------------------------------------------------------------------ users and master data

    private function users(): void
    {
        $people = [
            'admin' => ['Anand Krishnan', 'admin', 'AK', 'ADM001', '9840011001'],
            'priya' => ['Priya Nair', 'sales', 'PN', 'SAL001', '9840011002'],
            'arjun' => ['Arjun Mehta', 'sales', 'AM', 'SAL002', '9840011003'],
            'divya' => ['Divya Rao', 'sales', 'DR', 'SAL003', '9840011004'],
            'karthik' => ['Karthik Subramanian', 'operations', 'KS', 'OPS001', '9840011005'],
            'meena' => ['Meena Iyer', 'accountant', 'MI', 'ACC001', '9840011006'],
        ];
        foreach ($people as $k => [$name, $role, $code, $staff, $mobile]) {
            $this->u[$k] = User::create([
                'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@demo.indianrounds.test', 'password' => self::PASSWORD, 'role' => $role,
                'staff_code' => $staff, 'consultant_code' => $code, 'mobile' => $mobile, 'active' => true, 'account_status' => 'active',
                'password_changed_at' => now(), 'last_login_at' => now()->subHours(rand(1, 30)),
            ]);
        }
        (new CoreSeeder())->seedMaster();
        // destination -> consultant allocation for the busiest destinations
        foreach (['Bali' => 'priya', 'Maldives' => 'priya', 'Dubai' => 'arjun', 'Singapore' => 'arjun', 'Thailand' => 'divya', 'Kerala' => 'divya'] as $dest => $who) {
            DB::table('allocations')->insert(['destination' => $dest, 'consultant_id' => $this->u[$who]->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Core seeder's sample suppliers plus a realistic panel
        $panel = [
            ['Taj Vivanta Goa Reservations', 'reservations@tajgoa.demo', '+91 832 245 1234', 'Goa', 'India'],
            ['Backwater Ripples Resort', 'sales@backwaterripples.demo', '+91 477 270 3456', 'Kerala', 'India'],
            ['Bali Dream Escapes DMC', 'quotes@balidream.demo', '+62 361 555 0198', 'Bali', 'Indonesia'],
            ['Burj Heights Tours LLC', 'b2b@burjheights.demo', '+971 4 555 0142', 'Dubai', 'UAE'],
            ['Coral Atoll Maldives', 'agents@coralatoll.demo', '+960 330 0191', 'Maldives', 'Maldives'],
            ['Himalayan Houseboats Srinagar', 'bookings@himhouseboats.demo', '+91 194 250 0178', 'Kashmir', 'India'],
            ['Lion City Holidays', 'sales@lioncity.demo', '+65 6555 0123', 'Singapore', 'Singapore'],
            ['Siam Orchid Travel', 'reservations@siamorchid.demo', '+66 2 555 0156', 'Thailand', 'Thailand'],
        ];
        foreach ($panel as [$n, $e, $p, $d, $c]) {
            Supplier::firstOrCreate(['email' => $e], ['name' => $n, 'phone' => $p, 'destination' => $d, 'country' => $c]);
        }
    }

    private function banks(): void
    {
        $start = Carbon::create($this->today->year >= 2026 && $this->today->month >= 4 ? $this->today->year : $this->today->year - 1, 4, 1)->toDateString();
        $m = $this->u['meena'];
        $this->api(AccountController::class, 'storeBank', $m, ['name' => 'HDFC Current Account', 'kind' => 'bank', 'bank_name' => 'HDFC Bank', 'last4' => '4821', 'is_default' => true, 'opening_balance' => 1200000, 'opening_date' => $start]);
        $this->api(AccountController::class, 'storeBank', $m, ['name' => 'ICICI Savings', 'kind' => 'bank', 'bank_name' => 'ICICI Bank', 'last4' => '7305', 'opening_balance' => 200000, 'opening_date' => $start]);
        $this->api(AccountController::class, 'storeBank', $m, ['name' => 'Petty cash', 'kind' => 'cash', 'opening_balance' => 25000, 'opening_date' => $start]);
    }

    // ------------------------------------------------------------------ packages

    private function packages(): void
    {
        $cfg = config('crm');
        $common = "Daily breakfast\nAirport transfers in private AC vehicle\nSightseeing as per itinerary\nAll applicable hotel taxes";
        $excl = "Airfare unless mentioned\nLunch and dinner unless mentioned\nPersonal expenses, tips and insurance\nAnything not listed under inclusions";
        $terms = "50% advance to confirm the booking, balance 15 days before travel.\nCancellation 30+ days before travel: 25% of package cost. 15–30 days: 50%. Under 15 days: no refund.\nHotel check-in 2 pm, check-out 12 noon.";
        $defs = [
            ['Goa Beach Break', 'Goa', 'domestic', 4, 5, 18500, 11000, 14200, 8500, 5, 'Sun, sand and sea: a relaxed beach holiday with water sports and sunset cruises.', [['Arrival and beach evening', 'Pick-up from Dabolim airport, check-in and a sunset walk at Calangute.'], ['North Goa', 'Fort Aguada, Baga and Anjuna with a flea market stop.'], ['South Goa', 'Old Goa churches, Colva beach and a river cruise.'], ['Free day', 'Water sports or spa. Optional dolphin trip.'], ['Departure', 'Check-out and transfer to the airport.']], [['Peak (Christmas to New Year)', '12-20', '01-05', 1.45], ['Monsoon', '06-01', '09-15', 0.75]]],
            ['Kerala Backwaters and Hills', 'Kerala', 'domestic', 5, 6, 24500, 14500, 18900, 11200, 5, 'Munnar tea gardens, Thekkady wildlife and a night on a Alleppey houseboat.', [['Kochi arrival', 'Transfer to Munnar via Cheeyappara waterfalls.'], ['Munnar', 'Tea museum, Mattupetty dam and Eravikulam.'], ['Thekkady', 'Periyar boat ride and a spice plantation walk.'], ['Alleppey houseboat', 'Cruise the backwaters with lunch and dinner on board.'], ['Kovalam', 'Beach time and Ayurvedic massage (optional).'], ['Departure', 'Transfer to Trivandrum airport.']], [['Peak (Dec to Jan)', '12-15', '01-15', 1.3]]],
            ['Bali Honeymoon Special', 'Bali', 'honeymoon', 5, 6, 38500, 0, 29800, 0, 5, 'Private pool villa, candlelight dinner and a Nusa Penida day trip for two.', [['Arrival in Bali', 'Welcome flower garland and transfer to your Ubud villa.'], ['Ubud', 'Tegallalang rice terraces, monkey forest and the water temple.'], ['Kintamani and spa', 'Volcano view breakfast and a couples spa.'], ['Nusa Penida', 'Kelingking beach and Crystal Bay by speed boat.'], ['Seminyak', 'Beach club evening and candlelight dinner.'], ['Departure', 'Airport transfer.']], [['School holidays', '06-15', '07-31', 1.2]]],
            ['Dubai Family Fun', 'Dubai', 'international', 4, 5, 46500, 36500, 37200, 29000, 5, 'Desert safari, Burj Khalifa and Atlantis: made for families with children.', [['Arrival', 'Transfer to hotel and Dubai Mall fountain show.'], ['City tour', 'Burj Khalifa At The Top, Jumeirah beach and Dubai Frame.'], ['Desert safari', 'Dune bashing, camel ride and BBQ dinner with shows.'], ['Atlantis day', 'Aquaventure waterpark entry.'], ['Departure', 'Shopping and airport transfer.']], [['Dubai Shopping Festival', '12-15', '01-31', 1.25]]],
            ['Maldives Island Escape', 'Maldives', 'international', 3, 4, 64500, 32000, 51800, 25000, 5, 'Overwater villa on a private island with snorkelling and sunset fishing.', [['Arrival', 'Speedboat to the resort and welcome drink.'], ['Island day', 'Snorkelling with reef sharks and turtles.'], ['Sunset cruise', 'Dolphin watching and sunset fishing.'], ['Departure', 'Speedboat to Male airport.']], []],
            ['Kashmir Paradise', 'Kashmir', 'domestic', 6, 7, 29500, 17500, 22800, 13400, 5, 'Srinagar houseboat, Gulmarg gondola, Pahalgam and Sonmarg.', [['Srinagar arrival', 'Shikara ride on Dal Lake and houseboat stay.'], ['Mughal gardens', 'Nishat, Shalimar and Chashme Shahi.'], ['Gulmarg', 'Gondola phase 1 and 2 (own cost).'], ['Pahalgam', 'Betaab valley and Aru valley by pony.'], ['Sonmarg', 'Thajiwas glacier trek.'], ['Srinagar', 'Free time for shopping.'], ['Departure', 'Airport transfer.']], [['Summer peak', '05-01', '06-30', 1.3]]],
            ['Andaman Island Hopping', 'Andaman', 'domestic', 5, 6, 27500, 16000, 21000, 12000, 5, 'Havelock and Neil Island with scuba, Radhanagar beach and Ross Island.', [['Port Blair', 'Cellular Jail and the light and sound show.'], ['Havelock', 'Ferry to Havelock and Radhanagar beach.'], ['Elephant beach', 'Snorkelling and sea walk.'], ['Neil Island', 'Bharatpur and Laxmanpur beaches.'], ['Ross Island', 'Heritage walk and return to Port Blair.'], ['Departure', 'Airport transfer.']], []],
            ['Singapore and Malaysia Combo', 'Singapore', 'international', 6, 7, 58500, 46000, 46800, 36500, 5, 'Sentosa, Universal Studios, Genting Highlands and Kuala Lumpur.', [['Singapore arrival', 'Gardens by the Bay evening.'], ['Sentosa', 'Cable car, S.E.A. Aquarium and Wings of Time.'], ['Universal Studios', 'Full day at the park.'], ['To Kuala Lumpur', 'Coach transfer with Malacca stop.'], ['Genting Highlands', 'Skyway cable car and theme park.'], ['Kuala Lumpur', 'Petronas Towers and Batu Caves.'], ['Departure', 'Airport transfer.']], [['Year end', '12-18', '01-05', 1.3]]],
        ];
        $ops = $this->u['karthik'];
        $year = (int) $this->today->year;
        foreach ($defs as [$name, $dest, $cat, $n, $days, $ap, $cp, $ac, $cc, $gst, $summary, $itin, $seasons]) {
            $this->api(PackageController::class, 'store', $ops, [
                'name' => $name, 'destination' => $dest, 'category' => $cat, 'nights' => $n, 'days' => $days, 'adult_price' => $ap, 'child_price' => $cp,
                'adult_cost' => $ac, 'child_cost' => $cc, 'gst_rate' => $gst, 'summary' => $summary, 'inclusions' => $common, 'exclusions' => $excl, 'terms' => $terms,
                'itinerary' => array_map(fn ($d) => ['title' => $d[0], 'description' => $d[1]], $itin),
                'seasons' => array_map(function ($s) use ($ap, $cp, $ac, $cc, $year) {
                    [$label, $from, $to, $f] = $s;
                    $startYear = $from > $to ? $year : $year; // winter seasons run Dec to Jan
                    $endYear = $from > $to ? $year + 1 : $year;

                    return ['name' => $label, 'start_date' => "$startYear-$from", 'end_date' => "$endYear-$to", 'adult_price' => round($ap * $f, -2), 'child_price' => round($cp * $f, -2),
                        'adult_cost' => round($ac * $f, -2), 'child_cost' => round($cc * $f, -2)];
                }, $seasons),
            ]);
        }
    }

    // ------------------------------------------------------------------ leads

    private function leads(): void
    {
        $names = ['Rahul Sharma', 'Ananya Iyer', 'Vikram Singh', 'Sneha Reddy', 'Mohammed Faizal', 'Lakshmi Narayanan', 'Rohan Gupta', 'Pooja Deshmukh', 'Suresh Babu', 'Nisha Menon', 'Aditya Kapoor', 'Kavya Shetty',
            'Imran Sheikh', 'Deepa Venkatesh', 'Harish Patel', 'Swati Joshi', 'Manoj Kumar', 'Ritu Agarwal', 'Sanjay Nair', 'Tanvi Bhat', 'Gaurav Malhotra', 'Shilpa Rao', 'Arvind Pillai', 'Megha Kulkarni', 'Naveen Chandran',
            'Farah Khan', 'Siddharth Jain', 'Anjali Mishra', 'Prakash Raj', 'Bhavna Shah', 'Yusuf Ali', 'Chitra Subramaniam', 'Abhishek Verma', 'Radhika Menon', 'Kiran Hegde', 'Sunita Das', 'Ramesh Iyengar', 'Jasmine Dsouza',
            'Varun Bansal', 'Padma Lakshmi', 'Tarun Saxena', 'Aarti Pandey', 'Dinesh Kannan', 'Neha Chopra'];
        $dests = ['Goa', 'Kerala', 'Bali', 'Dubai', 'Maldives', 'Kashmir', 'Andaman', 'Singapore', 'Thailand'];
        $sources = ['Website', 'Referral', 'Instagram', 'Facebook', 'Walk-in', 'WhatsApp'];
        $types = ['Family', 'Couple', 'Honeymoon', 'Group', 'Solo'];
        $statuses = array_merge(
            array_fill(0, 7, 'New Lead'), array_fill(0, 4, 'Supplier Mail Sent'), array_fill(0, 3, 'Quotation Awaiting'), array_fill(0, 3, 'Quote Received'),
            array_fill(0, 5, 'Quote Sent to Customer'), array_fill(0, 5, 'Follow-up'), array_fill(0, 6, 'Booked'), array_fill(0, 2, 'Payment Pending'),
            array_fill(0, 3, 'Payment Received'), array_fill(0, 3, 'Travel Completed'), ['Closed'], array_fill(0, 2, 'Cancelled'));
        $owners = ['priya', 'arjun', 'divya'];
        $cities = ['Mumbai', 'Delhi', 'Bangalore', 'Chennai', 'Kolkata', 'Hyderabad'];
        $this->leadIds = [];

        foreach ($names as $i => $name) {
            $status = $statuses[$i] ?? 'New Lead';
            $dest = $dests[$i % count($dests)];
            $owner = $this->u[$owners[$i % 3]];
            $pkgType = $types[$i % 5];
            $adults = $pkgType === 'Solo' ? 1 : ($pkgType === 'Group' ? 6 : 2);
            $children = $pkgType === 'Family' ? 1 + ($i % 2) : 0;
            $past = in_array($status, ['Travel Completed', 'Closed'], true);
            $offset = $past ? -rand(15, 70) : (in_array($status, ['Booked', 'Payment Received', 'Payment Pending'], true) ? rand(20, 110) : rand(25, 160));
            $email = strtolower(str_replace(' ', '.', $name)).'@example.in';
            $res = $this->api(LeadController::class, 'store', $owner, [
                'customer_name' => $name, 'phone' => '9'.str_pad((string) (700000000 + $i * 3719231 % 299999999), 9, '0', STR_PAD_LEFT), 'email' => $email,
                'lead_date' => ($leadDate = $this->today->copy()->subDays(rand(3, 85))->setTime(rand(9, 18), rand(0, 59)))->toIso8601String(),
                'travel_date' => $this->d($offset), 'duration_days' => 4 + $i % 4, 'destination' => $dest, 'hotel_category' => ['3 Star', '4 Star', '5 Star', 'Boutique'][$i % 4],
                'meal_plan' => ['CP', 'MAP', 'AP'][$i % 3], 'package_type' => $pkgType, 'budget' => ['₹1.5 L', '₹2 L', '₹3 L', '₹4.5 L', '₹6 L'][$i % 5],
                'adults' => $adults, 'children' => $children, 'child_ages' => $children ? implode(',', array_slice([6, 10], 0, $children)) : '', 'rooms' => max(1, (int) ceil($adults / 2)),
                'room_type' => ['Double', 'Twin', 'Suite'][$i % 3], 'departure_from' => $cities[$i % 6], 'lead_source' => $sources[$i % 6],
                'special_requests' => ['Vegetarian meals', 'Anniversary surprise', 'Wheelchair access for parent', 'Late check-in', '', 'Honeymoon decoration'][$i % 6],
                'passport_available' => $i % 3 !== 0,
            ]);
            $id = $res['lead_id'];
            $owner = User::find($res['staff_id']) ?? $owner; // destination allocation may have given the lead to a colleague
            DB::table('leads')->where('lead_id', $id)->update(['created_at' => $leadDate, 'updated_at' => $leadDate->copy()->addDays(rand(0, 3))]);
            $this->leadIds[$id] = ['status' => $status, 'owner' => $owner, 'dest' => $dest, 'adults' => $adults, 'children' => $children, 'i' => $i];

            // pipeline state
            $selling = (int) round((60000 + ($i % 7) * 27000 + $adults * 9000 + $children * 5000) / 500) * 500;
            $supp = (int) round($selling * (0.76 + ($i % 4) * 0.02) / 500) * 500;
            $u = ['lead_status' => \App\Support\LeadStages::normalize($status), 'remarks' => ['Customer prefers morning flights', 'Waiting on passport copies', 'Comparing with another agent', 'Wants a discount on the villa', 'Very keen, call after 6 pm', 'Needs visa assistance'][$i % 6]];
            $order = ['New Lead' => 0, 'Supplier Mail Sent' => 1, 'Quotation Awaiting' => 2, 'Quote Received' => 3, 'Quote Sent to Customer' => 4, 'Follow-up' => 4, 'Booked' => 5, 'Payment Pending' => 5, 'Payment Received' => 6, 'Travel Completed' => 7, 'Closed' => 7, 'Cancelled' => 3][$status];
            if ($order >= 1) {
                $u += ['mail_status' => 'Sent', 'supplier_mail_date' => now()->subDays(rand(8, 40)), 'quote_type' => 'rsq', 'rsq_status' => $order >= 3 ? 'completed' : 'pending_reply'];
            }
            if ($order >= 3) {
                $u += ['supplier_quote_amount' => $supp, 'quote_received' => true, 'quote_received_date' => $this->d(-rand(6, 30))];
            }
            if ($order >= 4 || $status === 'Cancelled') {
                $u += ['selling_amount' => $selling, 'quote_sent_to_customer' => true, 'quote_sent_date' => $this->d(-rand(2, 20))];
            }
            if ($order >= 5) {
                $u += ['booking_status' => 'Booked', 'booking_amount_received' => round($selling * 0.4), 'payment_status' => $status === 'Payment Pending' ? 'Pending' : 'Part paid'];
            }
            if ($order >= 6) {
                $u += ['booking_amount_received' => $selling, 'payment_status' => 'Received'];
            }
            if ($status === 'Cancelled') {
                $u += ['booking_status' => 'Cancelled'];
            }
            $fu = match ($status) {
                'Follow-up' => [-2, -1, 0, 1, 3][$i % 5], 'Quote Sent to Customer' => [-1, 2, 4][$i % 3], 'New Lead' => [0, 1, 2][$i % 3], 'Quotation Awaiting' => 1, 'Payment Pending' => -3, default => null,
            };
            if ($fu !== null) {
                $u['follow_up_date'] = $this->today->copy()->addDays($fu)->setTime(11 + $i % 5, 30)->toIso8601String();
            }
            Lead::where('lead_id', $id)->update($u);

            // notes and contact log
            $notes = [
                ['Spoke to the customer, shared two options. They will confirm by weekend.', 'Asked for a lower budget. Suggested 3-star alternatives.'],
                ['Customer travelling with elderly parents, needs ground floor rooms.', 'Passport copies received on WhatsApp.'],
                ['Wants a candlelight dinner arranged on the second evening.'], ['Requested a call after 7 pm.'],
            ][$i % 4];
            foreach ($notes as $k => $n) {
                DB::table('lead_notes')->insert(['lead_id' => $id, 'note' => $n, 'created_by_id' => $owner->id, 'created_by' => $owner->name, 'created_at' => now()->subDays(rand(1, 12) + $k)]);
            }
            foreach (array_slice([['call', 'customer', 'Intro call'], ['whatsapp', 'customer', 'Sent itinerary PDF'], ['call', 'customer', 'Follow-up call']], 0, 1 + $i % 3) as $k => [$kind, $ch, $note]) {
                DB::table('contact_events')->insert(['lead_id' => $id, 'kind' => $kind, 'channel' => $ch, 'target' => '', 'note' => $note, 'user_id' => $owner->id, 'user_name' => $owner->name, 'timestamp' => now()->subDays(rand(1, 18))->subHours($k)]);
            }
        }
        $this->supplierMail();
    }

    /** @var array<string,array{status:string,owner:User,dest:string,adults:int,children:int,i:int}> */
    private array $leadIds = [];

    private function supplierMail(): void
    {
        $ops = $this->u['karthik'];
        $suppliers = Supplier::all();
        $n = 0;
        foreach ($this->leadIds as $id => $meta) {
            $order = ['New Lead' => 0, 'Supplier Mail Sent' => 1, 'Quotation Awaiting' => 2, 'Quote Received' => 3, 'Quote Sent to Customer' => 4, 'Follow-up' => 4, 'Booked' => 5, 'Payment Pending' => 5, 'Payment Received' => 6, 'Travel Completed' => 7, 'Closed' => 7, 'Cancelled' => 3][$meta['status']];
            if ($order < 1) {
                continue;
            }
            $lead = Lead::where('lead_id', $id)->first();
            $match = $suppliers->where('destination', $meta['dest'])->values();
            $picked = $match->isNotEmpty() ? $match->take(2) : $suppliers->take(2);
            $mailAt = $lead->supplier_mail_date ?? now()->subDays(10);
            DB::table('mail_history')->insert(['lead_id' => $id, 'subject' => "Quote request {$id} — {$meta['dest']} for {$lead->customer_name}", 'body' => "Dear partner,\n\nPlease share your best net rates for {$meta['adults']} adults".($meta['children'] ? " and {$meta['children']} children" : '').", {$meta['dest']}, travel date {$lead->travel_date}.\n\nRegards,\nIndian Rounds", 'sent_by' => $ops->email, 'sent_at' => $mailAt, 'status' => 'sent', 'provider' => 'graph']);
            DB::table('rsq_requests')->insert(['lead_id' => $id, 'status' => $order >= 3 ? 'completed' : ($order === 2 ? 'sent' : 'sent'), 'requested_by' => $meta['owner']->email, 'requested_by_name' => $meta['owner']->name,
                'requested_at' => $mailAt->copy()->subHours(5), 'note' => '', 'sent_at' => $mailAt, 'first_reply_at' => $order >= 3 ? $mailAt->copy()->addDay() : null, 'completed_at' => $order >= 3 ? $mailAt->copy()->addDays(2) : null, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($picked as $seq => $s) {
                $replied = $order >= 3 && $seq === 0 || ($order >= 3 && $seq === 1 && $n % 2 === 0);
                $amount = $replied ? (float) $lead->supplier_quote_amount * (1 + $seq * 0.06) : null;
                $ls = DB::table('lead_suppliers')->insertGetId(['lead_id' => $id, 'supplier_id' => $s->id, 'supplier_name' => $s->name, 'supplier_email' => $s->email, 'sequence' => $seq + 1,
                    'status' => $replied ? 'Quote Received' : ($order === 2 ? 'Awaiting Reply' : 'Email Sent'), 'emails_sent' => 1, 'first_email_at' => $mailAt, 'last_email_at' => $mailAt,
                    'reply_received' => $replied, 'reply_date' => $replied ? $mailAt->copy()->addDay() : null, 'quote_received' => $replied, 'quote_amount' => $amount, 'currency' => 'INR',
                    'hotel_offered' => $replied ? ['Sea View Resort 4★', 'Palm Court Hotel 4★', 'Lagoon Villa 5★'][$n % 3] : null, 'flight_included' => $replied ? false : null, 'transfer_included' => $replied ? true : null,
                    'valid_until' => $replied ? $this->d(14) : null, 'remarks' => $replied ? 'Rates valid for 14 days, subject to availability.' : null, 'followups' => $order === 2 ? 1 : 0,
                    'last_update' => now()->subDays(rand(1, 8)), 'updated_by' => $ops->email, 'created_at' => $mailAt]);
                if ($replied) {
                    DB::table('supplier_replies')->insert(['lead_id' => $id, 'supplier_id' => $s->id, 'lead_supplier_id' => $ls, 'graph_message_id' => "demo-reply-{$id}-{$s->id}", 'from_email' => $s->email, 'from_name' => $s->name,
                        'to_emails' => json_encode(['enquiry@indianrounds.in']), 'cc_emails' => json_encode([]), 'subject' => "RE: Quote request {$id}", 'body_text' => "Dear team,\n\nPlease find our net rate of INR ".number_format($amount).' for the requested dates. Valid for 14 days.',
                        'body_preview' => 'Please find our net rate of INR '.number_format($amount).' for the requested dates.', 'received_at' => $mailAt->copy()->addDay()->addHours(3), 'status' => 'matched', 'matched_by' => 'thread',
                        'handled_by' => $ops->id, 'handled_at' => $mailAt->copy()->addDays(2), 'notified_at' => $mailAt->copy()->addDays(2), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $n++;
        }
        // two unmatched messages for the Supplier Inbox
        foreach ([['Siam Orchid Travel', 'reservations@siamorchid.demo', 'Availability update for peak season', 'We have limited availability from 20 Dec to 2 Jan. Please confirm early.'],
            ['Bali Dream Escapes DMC', 'quotes@balidream.demo', 'New villa rates for 2027', 'Attached are our new villa rates effective from January.']] as $k => [$name, $email, $subject, $body]) {
            DB::table('supplier_replies')->insert(['lead_id' => null, 'supplier_id' => Supplier::where('email', $email)->value('id'), 'graph_message_id' => "demo-unmatched-$k", 'from_email' => $email, 'from_name' => $name,
                'to_emails' => json_encode(['enquiry@indianrounds.in']), 'cc_emails' => json_encode([]), 'subject' => $subject, 'body_text' => $body, 'body_preview' => $body, 'received_at' => now()->subHours(6 + $k * 20),
                'status' => 'unmatched', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ------------------------------------------------------------------ customers and deals

    private function customersAndDeals(): void
    {
        // convert the leads that have reached the quote stage or beyond
        foreach ($this->leadIds as $id => $meta) {
            if (! in_array($meta['status'], ['Quote Sent to Customer', 'Follow-up', 'Booked', 'Payment Pending', 'Payment Received', 'Travel Completed', 'Closed', 'Cancelled'], true) || $meta['i'] % 3 === 2 && $meta['status'] === 'Follow-up') {
                continue;
            }
            $r = $this->api(LeadConvertController::class, 'convert', $meta['owner'], ['customer' => ['type' => $meta['adults'] > 2 ? 'family' : 'individual']], [$id]);
            $this->leadIds[$id]['customer_id'] = $r['customer']['id'];
            $this->leadIds[$id]['deal_id'] = $r['deal']['id'];
        }
        // richer customer profiles: address, GST, travellers
        $states = [['Chennai', 'Tamil Nadu', '600'], ['Bengaluru', 'Karnataka', '560'], ['Mumbai', 'Maharashtra', '400'], ['Kochi', 'Kerala', '682'], ['Hyderabad', 'Telangana', '500'], ['Coimbatore', 'Tamil Nadu', '641'], ['Pune', 'Maharashtra', '411']];
        foreach (Customer::orderBy('id')->get() as $k => $c) {
            [$city, $state, $pin] = $states[$k % count($states)];
            $c->update(['city' => $city, 'state' => $state, 'pincode' => $pin.str_pad((string) (10 + $k * 7), 3, '0', STR_PAD_LEFT), 'address_line' => (10 + $k * 3).', '.['MG Road', 'Anna Nagar 2nd Street', 'Lake View Colony', 'Green Park Avenue', 'Temple Road'][$k % 5], 'country' => 'India']);
            $first = explode(' ', $c->name)[0];
            $this->api(CustomerController::class, 'update', $this->u['admin'], ['travellers' => [
                ['name' => $c->name, 'relation' => 'Self', 'dob' => (1978 + $k % 18).'-0'.(1 + $k % 9).'-1'.($k % 9), 'nationality' => 'Indian', 'passport_no' => 'N'.(2000000 + $k * 7919), 'passport_expiry' => (2028 + $k % 6).'-0'.(1 + $k % 9).'-15', 'preferences' => ['Vegetarian', 'Window seat', 'No spicy food', 'Aisle seat'][$k % 4]],
                ['name' => $first.' (companion)', 'relation' => ['Spouse', 'Friend', 'Parent'][$k % 3], 'nationality' => 'Indian', 'passport_no' => 'M'.(3100000 + $k * 6151), 'passport_expiry' => (2027 + $k % 7).'-0'.(1 + $k % 9).'-20'],
            ]], [(string) $c->id]);
        }

        // corporate and agent customers with their own deals
        $ops = $this->u['karthik'];
        $corp = [
            ['TechNova Solutions Pvt Ltd', 'corporate', 'Chennai', 'Tamil Nadu', '33AABCT1234F1Z5', 'priya', 'Annual team offsite, 24 employees', 'Bali', 24, 612000],
            ['Greenleaf Pharma', 'corporate', 'Hyderabad', 'Telangana', '36AAECG5678K1ZP', 'arjun', 'Incentive trip for top performers', 'Dubai', 12, 504000],
            ['Wanderers Travel Agency', 'agent', 'Kochi', 'Kerala', '32AAFCW9012L1Z2', 'divya', 'Group departure for a travel agent partner', 'Singapore', 10, 468000],
            ['Sundaram Family Trust', 'family', 'Coimbatore', 'Tamil Nadu', null, 'divya', 'Family reunion trip', 'Kerala', 14, 301000],
        ];
        $this->corpDeals = [];
        foreach ($corp as $k => [$name, $type, $city, $state, $gst, $who, $title, $dest, $pax, $value]) {
            $o = $this->u[$who];
            $c = $this->api(CustomerController::class, 'store', $o, [
                'name' => $name, 'type' => $type, 'company' => $type === 'family' ? null : $name, 'phone' => '9'.(800000000 + $k * 7771111), 'email' => 'travel@'.strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $name)[0])).'.example.in',
                'gstin' => $gst, 'city' => $city, 'state' => $state, 'address_line' => (100 + $k * 11).', Industrial Estate Road', 'pincode' => ['600032', '500081', '682016', '641012'][$k], 'country' => 'India', 'source' => 'Referral',
                'contacts' => [['name' => ['Ramya Sekar', 'Dr. Vivek Rao', 'Basheer Ahmed', 'Gowri Sundaram'][$k], 'designation' => ['HR Manager', 'Marketing Head', 'Director', 'Trustee'][$k], 'phone' => '9'.(840000000 + $k * 3333), 'email' => 'contact@example.in', 'is_primary' => true]],
            ]);
            $stage = ['negotiation', 'quoted', 'won', 'won'][$k];
            $d = $this->api(DealController::class, 'store', $o, ['customer_id' => $c['id'], 'name' => $title, 'destination' => $dest, 'stage' => $stage, 'travel_date' => $this->d([75, 120, 45, 30][$k]),
                'duration_days' => 6, 'adults' => $pax, 'selling_amount' => $value, 'supplier_cost' => round($value * 0.77), 'expected_close' => $this->d([10, 20, -12, -20][$k]), 'owner_id' => (string) $o->id]);
            $this->corpDeals[] = ['deal' => $d, 'customer' => $c, 'owner' => $o, 'dest' => $dest];
        }
        // a few lost deals and open enquiries to make the board and forecast busy
        $extra = [['lost', 'Price too high compared with a competitor', 'Maldives', 'priya', 410000], ['lost', 'Postponed to next year', 'Bali', 'priya', 285000], ['enquiry', null, 'Thailand', 'divya', 190000], ['enquiry', null, 'Kashmir', 'arjun', 150000], ['quoted', null, 'Goa', 'arjun', 98000]];
        $custs = Customer::orderBy('id')->get();
        foreach ($extra as $k => [$stage, $reason, $dest, $who, $value]) {
            $c = $custs[($k * 3 + 1) % $custs->count()];
            $o = $this->u[$who];
            $this->api(DealController::class, 'store', $this->u['admin'], ['customer_id' => (string) $c->id, 'name' => "$dest trip — {$c->name}", 'destination' => $dest, 'stage' => $stage, 'lost_reason' => $reason,
                'travel_date' => $this->d(rand(60, 170)), 'duration_days' => 5, 'adults' => 2, 'selling_amount' => $value, 'supplier_cost' => round($value * 0.78), 'expected_close' => $this->d(rand(-8, 40)), 'owner_id' => (string) $o->id]);
        }
        // spread win dates over the financial year so the dashboard and forecasts have history
        $won = Deal::where('stage', 'won')->orderBy('id')->get();
        foreach ($won as $k => $d) {
            $closed = $this->today->copy()->subDays(2 + $k * 9)->setTime(12, 0);
            $d->closed_at = $closed;
            $d->expected_close = $closed->toDateString();
            if ($k % 4 === 1) { // a few trips leave in the next three weeks so the dashboard shows departures
                $d->travel_date = $this->d(3 + $k);
            }
            $d->save();
        }
    }

    /** @var list<array{deal:array,customer:array,owner:User,dest:string}> */
    private array $corpDeals = [];

    // ------------------------------------------------------------------ quotes

    private function quoteBody(Deal $deal, ?TravelPackage $pkg, int $adults, int $children, array $over = []): array
    {
        $date = $deal->travel_date?->format('Y-m-d');
        $rates = $pkg ? $pkg->ratesFor($date) : ['adult_price' => 40000, 'child_price' => 25000, 'gst_rate' => 5, 'season' => null];
        $lines = [];
        $tag = $rates['season'] ? " ({$rates['season']})" : '';
        $lines[] = ['kind' => 'package', 'description' => ($pkg?->name ?? "{$deal->destination} package").' — per adult'.$tag, 'qty' => $adults, 'unit_price' => $rates['adult_price'], 'discount' => 0, 'tax_rate' => $rates['gst_rate']];
        if ($children > 0 && ($rates['child_price'] ?? 0) > 0) {
            $lines[] = ['kind' => 'package', 'description' => ($pkg?->name ?? "{$deal->destination} package").' — per child'.$tag, 'qty' => $children, 'unit_price' => $rates['child_price'], 'discount' => 0, 'tax_rate' => $rates['gst_rate']];
        }
        if ($adults >= 4) {
            $lines[0]['discount'] = round($adults * $rates['adult_price'] * 0.03, -2); // group discount
        }
        $full = $pkg ? TravelPackage::with(['seasons', 'itineraryDays'])->find($pkg->id) : null;

        return $over + [
            'customer_id' => (string) $deal->customer_id, 'deal_id' => (string) $deal->id, 'package_id' => $pkg ? (string) $pkg->id : null, 'title' => $deal->destination.' — '.($pkg?->name ?? 'holiday package'),
            'destination' => $deal->destination, 'travel_date' => $date, 'duration_days' => $deal->duration_days ?: ($pkg?->days), 'adults' => $adults, 'children' => $children, 'lines' => $lines,
            'intro' => 'Thank you for choosing Indian Rounds. Here is the proposal we put together for your trip.', 'inclusions' => $full?->inclusions, 'exclusions' => $full?->exclusions, 'terms' => $full?->terms,
            'itinerary' => $full ? $full->itineraryDays->map(fn ($d) => ['title' => $d->title, 'description' => $d->description])->all() : [],
        ];
    }

    private function quotes(): void
    {
        $pkgs = TravelPackage::all()->keyBy('destination');
        $deals = Deal::with('customer')->whereIn('stage', ['quoted', 'negotiation', 'won'])->orderBy('id')->get();
        $this->quotedDeals = [];
        foreach ($deals as $k => $deal) {
            $owner = $this->u['admin']; // acts for the deal owner (the admin can see every customer)
            $ownerId = (string) ($deal->owner_id ?? $owner->id);
            $adults = max(1, $deal->adults);
            $body = $this->quoteBody($deal, $pkgs[$deal->destination] ?? null, $adults, $deal->children, ['issue_date' => $this->d(-rand(12, 70)), 'valid_until' => $this->d(rand(-25, 10)), 'owner_id' => $ownerId]);
            $q = $this->api(QuoteController::class, 'store', $owner, $body);
            $this->quotedDeals[$deal->id] = $q['id'];
            $id = $q['id'];
            if ($deal->stage === 'won') {
                $this->api(QuoteController::class, 'setStatus', $owner, ['status' => 'sent'], [$id]);
                $this->api(QuoteController::class, 'setStatus', $owner, ['status' => 'accepted'], [$id]);
            } elseif ($k % 5 === 4) {
                // stays a draft
            } elseif ($k % 5 === 3) {
                $this->api(QuoteController::class, 'setStatus', $owner, ['status' => 'sent'], [$id]);
                $this->api(QuoteController::class, 'setStatus', $owner, ['status' => 'rejected', 'reason' => 'Went with a cheaper option'], [$id]);
            } else {
                $this->api(QuoteController::class, 'setStatus', $owner, ['status' => 'sent'], [$id]);
                if ($k % 5 === 1) { // revise once with a small discount
                    $rev = $this->api(QuoteController::class, 'revise', $owner, [], [$id]);
                    $this->api(QuoteController::class, 'update', $owner, ['title' => $rev['title'].' (revised)', 'intro' => 'Revised as discussed: dates and hotel category updated.'], [$rev['id']]);
                    $this->api(QuoteController::class, 'setStatus', $owner, ['status' => 'sent'], [$rev['id']]);
                    $this->quotedDeals[$deal->id] = $rev['id'];
                }
            }
        }
        // age a few so the list shows expired and older quotes
        Quote::where('status', 'sent')->orderBy('id')->limit(2)->update(['valid_until' => $this->d(-9)]);
    }

    /** @var array<int|string,string> */
    private array $quotedDeals = [];

    // ------------------------------------------------------------------ invoices and money in

    private function invoices(): void
    {
        $fin = $this->u['meena'];
        $banks = DB::table('bank_accounts')->pluck('id', 'name');
        $hdfc = (string) $banks['HDFC Current Account'];
        $icici = (string) $banks['ICICI Savings'];
        $cash = (string) $banks['Petty cash'];
        $modes = [['upi', $hdfc], ['bank_transfer', $hdfc], ['bank_transfer', $icici], ['card', $hdfc], ['cash', $cash], ['upi', $icici]];

        $won = Deal::with('customer')->where('stage', 'won')->orderBy('closed_at')->get();
        $plan = []; // [deal, how paid]
        $states = ['paid', 'paid', 'partial', 'paid', 'partial', 'overdue', 'paid', 'partial', 'unpaid', 'credit', 'partial'];
        foreach ($won as $k => $deal) {
            $plan[] = [$deal, $states[$k % count($states)]];
        }

        $this->invoiceByDeal = [];
        $this->wonBills = [];
        foreach ($plan as $k => [$deal, $state]) {
            $issue = Carbon::parse($deal->closed_at)->subDays(rand(0, 3))->toDateString();
            $issue = min($issue, $this->d(-1));
            $qid = $this->quotedDeals[$deal->id] ?? null;
            if ($qid) {
                $inv = $this->api(InvoiceController::class, 'fromQuote', $fin, [], [(string) $qid]);
                $inv = $this->api(InvoiceController::class, 'update', $fin, ['issue_date' => $issue], [$inv['id']]);
            } else {
                $selling = max(40000, (float) $deal->selling_amount);
                $inv = $this->api(InvoiceController::class, 'store', $fin, ['customer_id' => (string) $deal->customer_id, 'deal_id' => (string) $deal->id, 'title' => "{$deal->destination} holiday", 'issue_date' => $issue,
                    'destination' => $deal->destination, 'travel_date' => $deal->travel_date?->format('Y-m-d'), 'adults' => $deal->adults, 'children' => $deal->children,
                    'lines' => [['kind' => 'package', 'description' => "{$deal->destination} holiday package", 'qty' => 1, 'unit_price' => round($selling / 1.05, 2), 'tax_rate' => 5]]]);
            }
            $total = (float) $inv['total'];
            $travel = $deal->travel_date?->format('Y-m-d') ?? $this->d(40);
            $adv = round($total * 0.4, 2);
            $due1 = $issue;
            $due2 = $state === 'overdue' ? $this->d(-6) : max($this->d(7), Carbon::parse($travel)->subDays(15)->toDateString());
            $inv = $this->api(InvoiceController::class, 'update', $fin, ['installments' => [['label' => 'Advance', 'due_date' => $due1, 'amount' => $adv], ['label' => 'Balance', 'due_date' => $due2, 'amount' => round($total - $adv, 2)]]], [$inv['id']]);
            $inv = $this->api(InvoiceController::class, 'issue', $fin, [], [$inv['id']]);
            $this->invoiceByDeal[$deal->id] = $inv['id'];
            [$mode, $bank] = $modes[$k % count($modes)];
            $payDate = fn (int $offset) => min($this->d(-1), Carbon::parse($issue)->addDays($offset)->toDateString());
            $pay = fn (float $amt, string $date, string $m, string $b, string $ref) => $this->api(InvoiceController::class, 'addReceipt', $fin, ['amount' => round($amt, 2), 'received_date' => $date, ...($m === 'cash' && $amt > 25000 ? ['mode' => 'bank_transfer', 'bank_account_id' => $hdfc] : ['mode' => $m, 'bank_account_id' => $b]), 'reference' => $ref], [$inv['id']]);

            if (in_array($state, ['paid', 'credit'], true)) {
                $pay($adv, $payDate(rand(0, 2)), $mode, $bank, 'UTR'.rand(100000, 999999));
                [$m2, $b2] = $modes[($k + 2) % count($modes)];
                $pay($total - $adv, $payDate(rand(12, 30)), $m2, $b2, 'UTR'.rand(100000, 999999));
            } elseif ($state === 'partial') {
                $pay($adv, $payDate(rand(0, 2)), $mode, $bank, 'UTR'.rand(100000, 999999));
            } elseif ($state === 'overdue') {
                $pay($adv, $payDate(1), $mode, $bank, 'UTR'.rand(100000, 999999));
            }
            if ($state === 'credit') { // guest dropped out: credit note, then refund of what was paid for them
                $cn = $this->api(InvoiceController::class, 'addCreditNote', $fin, ['amount' => round($total * 0.2, 2), 'reason' => 'One guest cancelled, partial refund as per policy', 'issue_date' => $payDate(35)], [$inv['id']]);
                $this->api(InvoiceController::class, 'addRefund', $fin, ['amount' => $cn['refundable'], 'mode' => 'bank_transfer', 'bank_account_id' => $hdfc, 'reference' => 'REFUND'.rand(1000, 9999), 'received_date' => $payDate(38)], [$inv['id']]);
            }
            $this->wonBills[] = ['deal' => $deal, 'total' => $total];
        }

        // extra documents: a cancelled invoice, a draft, an advance on account, an advance that gets applied
        $customers = Customer::orderBy('id')->get();
        $open = Deal::whereIn('stage', ['quoted', 'negotiation'])->orderBy('id')->get();
        if ($open->count() >= 2) {
            $d = $open[0];
            $x = $this->api(InvoiceController::class, 'store', $fin, ['customer_id' => (string) $d->customer_id, 'deal_id' => (string) $d->id, 'title' => 'Issued by mistake', 'issue_date' => $this->today->toDateString(),
                'lines' => [['description' => 'Holiday package', 'qty' => 1, 'unit_price' => 52000, 'tax_rate' => 5]]]);
            $this->api(InvoiceController::class, 'issue', $fin, [], [$x['id']]);
            $this->api(InvoiceController::class, 'cancel', $fin, ['reason' => 'Raised against the wrong customer'], [$x['id']]);
            $d2 = $open[1];
            $this->api(InvoiceController::class, 'store', $fin, ['customer_id' => (string) $d2->customer_id, 'deal_id' => (string) $d2->id, 'title' => "{$d2->destination} — draft invoice", 'issue_date' => $this->today->toDateString(),
                'lines' => [['description' => "{$d2->destination} package", 'qty' => 2, 'unit_price' => 31500, 'tax_rate' => 5]]]);
        }
        $c1 = $customers[2];
        $adv = $this->api(ReceiptController::class, 'addAdvance', $fin, ['amount' => 25000, 'mode' => 'upi', 'bank_account_id' => $hdfc, 'reference' => 'ADV-UPI-8841', 'received_date' => $this->d(-3), 'notes' => 'Token amount for next year trip'], [(string) $c1->id]);
        // an advance that is applied to a fresh invoice
        $c2 = $customers[4];
        $adv2 = $this->api(ReceiptController::class, 'addAdvance', $fin, ['amount' => 15000, 'mode' => 'bank_transfer', 'bank_account_id' => $icici, 'reference' => 'NEFT-228119', 'received_date' => $this->d(-9)], [(string) $c2->id]);
        $y = $this->api(InvoiceController::class, 'store', $fin, ['customer_id' => (string) $c2->id, 'title' => 'Weekend getaway', 'issue_date' => $this->d(-8), 'lines' => [['description' => 'Weekend getaway package', 'qty' => 2, 'unit_price' => 19000, 'tax_rate' => 5]]]);
        $this->api(InvoiceController::class, 'issue', $fin, [], [$y['id']]);
        $this->api(ReceiptController::class, 'apply', $fin, ['invoice_id' => $y['id']], [$adv2['id']]);
    }

    /** @var array<int,string> */
    private array $invoiceByDeal = [];

    /** @var list<array{deal:Deal,total:float}> */
    private array $wonBills = [];

    // ------------------------------------------------------------------ money out and forecasts

    private function payables(): void
    {
        $fin = $this->u['meena'];
        $banks = DB::table('bank_accounts')->pluck('id', 'name');
        $hdfc = (string) $banks['HDFC Current Account'];
        $icici = (string) $banks['ICICI Savings'];
        $pettyCash = (string) $banks['Petty cash'];
        $suppliers = Supplier::all()->keyBy('destination');
        $acc = fn (string $code) => (string) DB::table('accounts')->where('code', $code)->value('id');

        // a supplier bill for every won trip (cost is roughly three quarters of the sale, excluding GST)
        $seq = 0;
        foreach ($this->wonBills as $k => ['deal' => $deal, 'total' => $total]) {
            $supplier = $suppliers[$deal->destination] ?? Supplier::first();
            $cost = round($total / 1.05 * (0.64 + ($k % 4) * 0.015), -2);
            $hotel = round($cost * 0.62, -2);
            $billDate = Carbon::parse($deal->closed_at)->addDays(2)->toDateString();
            $billDate = min($billDate, $this->d(-1));
            $bill = $this->api(PayablesController::class, 'storeBill', $fin, [
                'supplier_id' => (string) $supplier->id, 'supplier_ref' => 'INV-'.(2100 + $seq++), 'deal_id' => (string) $deal->id, 'bill_date' => $billDate, 'due_date' => Carbon::parse($billDate)->addDays(21)->toDateString(),
                'lines' => [['description' => "Hotel stay — {$deal->destination}", 'amount' => $hotel], ['description' => 'Transfers and sightseeing', 'amount' => round($cost - $hotel, 2)]],
            ]);
            $mode = [0, 0, 1, 0, 0, 2, 0, 1, 0, 0][$k % 10]; // mostly settled, a few part-paid, one open
            $pay = fn (float $a, string $d) => $this->api(PayablesController::class, 'payBill', $fin, ['amount' => $a, 'paid_date' => min($d, $this->d(-1)), 'mode' => 'bank_transfer', 'bank_account_id' => $k % 3 === 0 ? $icici : $hdfc, 'reference' => 'SUP'.rand(10000, 99999)], [$bill['id']]);
            if ($mode === 0) {
                $pay((float) $bill['total'], Carbon::parse($billDate)->addDays(12)->toDateString());
            } elseif ($mode === 1) {
                $pay(round($bill['total'] * 0.5, 2), Carbon::parse($billDate)->addDays(9)->toDateString());
            }
        }
        // an advance paid to a supplier ahead of a bill
        if ($s = Supplier::where('destination', 'Maldives')->first()) {
            $this->api(PayablesController::class, 'advance', $fin, ['amount' => 40000, 'paid_date' => $this->d(-4), 'mode' => 'bank_transfer', 'bank_account_id' => $icici, 'reference' => 'ADV-COR-0098'], [(string) $s->id]);
        }

        // running costs for each month of the financial year so far
        $month = Carbon::parse($this->today->month >= 4 ? $this->today->year.'-04-01' : ($this->today->year - 1).'-04-01');
        while ($month->lte($this->today)) {
            $on = fn (int $day) => $month->copy()->setDay($day)->toDateString();
            $rows = [
                ['6200', 22000, 'Rent — Cathedral Road office', 'Mr. Hariharan (landlord)', 7, 'bank_transfer'], ['6100', 62000, 'Staff salaries', 'Payroll', 28, 'bank_transfer'],
                ['6400', 6400, 'Software and subscriptions', 'Zoho, Google Workspace', 8, 'card'], ['6300', 9000 + (int) $month->format('n') * 600, 'Meta and Google ads', 'Meta Platforms', 10, 'card'],
                ['6500', 650, 'Bank charges', 'HDFC Bank', 30, 'bank_transfer'], ['6600', 2500 + (int) $month->format('n') * 200, 'Local conveyance', 'Staff', 20, 'cash'],
            ];
            foreach ($rows as [$code, $amt, $payee, $who, $day, $mode]) {
                $date = $on(min($day, (int) $month->daysInMonth));
                if ($date >= $this->today->toDateString()) {
                    continue;
                }
                $this->api(PayablesController::class, 'storeMoney', $fin, ['kind' => 'expense', 'account_id' => $acc($code), 'amount' => $amt, 'entry_date' => $date, 'payee' => $who, 'reference' => $payee, 'mode' => $mode, 'bank_account_id' => $mode === 'cash' ? $pettyCash : $hdfc]);
            }
            $this->api(PayablesController::class, 'storeMoney', $fin, ['kind' => 'income', 'account_id' => $acc('4100'), 'amount' => 3200 + (int) $month->format('n') * 300, 'entry_date' => $month->copy()->setDay(min(15, (int) $month->daysInMonth))->toDateString() < $this->today->toDateString() ? $month->copy()->setDay(15)->toDateString() : $this->d(-1),
                'payee' => 'Hotel commission', 'reference' => 'Commission credit', 'mode' => 'bank_transfer', 'bank_account_id' => $icici]);
            $month->addMonthNoOverflow();
        }

        // forecast targets for the financial year
        $fy = ForecastController::fyOf($this->today);
        foreach ([[1, 1300000], [2, 1100000], [3, 1200000], [4, 1000000]] as [$q, $amt]) {
            $this->api(ForecastController::class, 'setTarget', $this->u['admin'], ['fy' => $fy, 'quarter' => $q, 'amount' => $amt]);
        }
        foreach ([['priya', 450000], ['arjun', 350000], ['divya', 300000]] as [$who, $amt]) {
            foreach ([1, 2, 3, 4] as $q) {
                $this->api(ForecastController::class, 'setTarget', $this->u['admin'], ['fy' => $fy, 'quarter' => $q, 'user_id' => (string) $this->u[$who]->id, 'amount' => round($amt * [0.9, 1.05, 1.1, 0.95][$q - 1], -3)]);
            }
        }
    }

    // ------------------------------------------------------------------ activities, notes, chat

    private function activitiesAndChat(): void
    {
        $leads = array_keys($this->leadIds);
        $deals = Deal::with('customer')->orderBy('id')->get();
        $titles = [
            ['call', 'Call to confirm hotel preference'], ['follow_up', 'Follow up on the quote'], ['email', 'Send revised itinerary'], ['meeting', 'Office visit to finalise the booking'], ['task', 'Collect passport copies'],
            ['call', 'Remind about the balance payment'], ['task', 'Book airport transfers'], ['email', 'Share visa checklist'], ['follow_up', 'Check if the customer has decided'], ['meeting', 'Video call to walk through the itinerary'],
        ];
        $owners = ['priya', 'arjun', 'divya', 'karthik', 'admin'];
        foreach (range(0, 33) as $i) {
            [$type, $title] = $titles[$i % count($titles)];
            $owner = $this->u[$owners[$i % 5]];
            $row = ['type' => $type, 'title' => $title, 'details' => $i % 3 === 0 ? 'Customer asked for a call-back after 6 pm.' : null, 'priority' => ['low', 'medium', 'high'][$i % 3],
                'assigned_to' => $owner->id, 'created_by' => $owner->id, 'due_at' => now()->addDays([-5, -2, -1, 0, 0, 1, 2, 3, 6, 9][$i % 10])->setTime(10 + $i % 7, [0, 30][$i % 2]),
                'completed_at' => $i % 4 === 0 ? now()->subDays(rand(1, 6)) : null, 'completed_by' => $i % 4 === 0 ? $owner->id : null];
            if ($i % 3 === 0 && $leads) {
                $row['lead_id'] = $leads[$i % count($leads)];
            } elseif ($deals->isNotEmpty()) {
                $d = $deals[$i % $deals->count()];
                $row['deal_id'] = $d->id;
                $row['customer_id'] = $d->customer_id;
            }
            Activity::create($row);
        }

        // notes on customers and deals
        $admin = $this->u['admin'];
        foreach ($deals->take(12) as $k => $d) {
            DB::table('crm_notes')->insert(['entity_type' => 'deal', 'entity_id' => $d->id, 'note' => ['Customer wants to add a Dubai stopover.', 'Advance received, sending vouchers a week before travel.', 'Needs vegetarian meals throughout.', 'Asked to upgrade to a villa, quoting the difference.'][$k % 4], 'created_by_id' => $admin->id, 'created_by_name' => $admin->name, 'created_at' => now()->subDays(rand(1, 20))]);
            if ($k % 2 === 0) {
                DB::table('crm_notes')->insert(['entity_type' => 'customer', 'entity_id' => $d->customer_id, 'note' => ['Repeat customer, travelled with us twice before.', 'Prefers WhatsApp over calls.', 'Celebrating their 10th anniversary on this trip.'][$k % 3], 'created_by_id' => $admin->id, 'created_by_name' => $admin->name, 'created_at' => now()->subDays(rand(1, 30))]);
            }
        }

        // team chat: one group and two direct conversations
        $group = ChatConversation::create(['type' => 'group', 'name' => 'Sales team', 'created_by' => $admin->id, 'last_message_at' => now()]);
        foreach (['admin', 'priya', 'arjun', 'divya', 'karthik'] as $k) {
            ChatParticipant::create(['conversation_id' => $group->id, 'user_id' => $this->u[$k]->id]);
        }
        $lines = [
            ['admin', 'Good morning team. Quarter-end is close: let us push the pending quotes this week.', 52], ['priya', 'Bali villa rates are in. Sending the Mehta family their quote today.', 51],
            ['arjun', 'Dubai shopping festival dates are out. I will update the package prices.', 30], ['divya', 'Can someone from ops confirm the Kerala houseboat availability for 24 Dec?', 26],
            ['karthik', 'Confirmed. Two houseboats are blocked until Friday. Please book before then.', 25], ['divya', 'Thanks Karthik! Booking it now.', 24], ['admin', 'Great work everyone. Won three deals this week.', 5],
            ['priya', 'Reminder: balance payments are due on Friday for the Maldives and Bali groups.', 3],
        ];
        $lastId = 0;
        foreach ($lines as [$who, $text, $hoursAgo]) {
            $m = ChatMessage::create(['conversation_id' => $group->id, 'sender_id' => $this->u[$who]->id, 'body' => $text]);
            DB::table('chat_messages')->where('id', $m->id)->update(['created_at' => now()->subHours($hoursAgo)]);
            $lastId = $m->id;
        }
        DB::table('chat_conversations')->where('id', $group->id)->update(['last_message_at' => now()->subHours(3)]);
        DB::table('chat_participants')->where('conversation_id', $group->id)->whereNotIn('user_id', [$this->u['admin']->id])->update(['last_read_message_id' => $lastId - 1]); // admin has read all, others miss the last
        DB::table('chat_participants')->where('conversation_id', $group->id)->where('user_id', $this->u['admin']->id)->update(['last_read_message_id' => $lastId]);

        $dm = ChatConversation::create(['type' => 'direct', 'direct_key' => min($admin->id, $this->u['priya']->id).':'.max($admin->id, $this->u['priya']->id), 'created_by' => $admin->id, 'last_message_at' => now()]);
        foreach (['admin', 'priya'] as $k) {
            ChatParticipant::create(['conversation_id' => $dm->id, 'user_id' => $this->u[$k]->id]);
        }
        foreach ([['priya', 'Anand, the TechNova team wants a revised Bali quote with a gala dinner.', 8], ['admin', 'Sure. Add the dinner as a separate line and keep the group discount.', 7], ['priya', 'Done, sending it now.', 6]] as [$who, $text, $h]) {
            $m = ChatMessage::create(['conversation_id' => $dm->id, 'sender_id' => $this->u[$who]->id, 'body' => $text]);
            DB::table('chat_messages')->where('id', $m->id)->update(['created_at' => now()->subHours($h)]);
        }
        DB::table('chat_conversations')->where('id', $dm->id)->update(['last_message_at' => now()->subHours(6)]);
    }
}

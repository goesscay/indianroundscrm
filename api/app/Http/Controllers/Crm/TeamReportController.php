<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeamReport;
use App\Support\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Team performance: for Super Admins only. Everyone else gets "not found", as if the page did not exist. */
class TeamReportController extends Controller
{
    private function guard(Request $request): void
    {
        if (! $request->user()?->isSuperAdmin()) {
            Fail::notFound('Not found');
        }
    }

    /** from / to as IST dates; defaults to this month so far. Never more than a year. @return array{0:string,1:string} */
    private function period(Request $request): array
    {
        $today = Carbon::now('Asia/Kolkata');
        $from = (string) $request->query('from', $today->copy()->startOfMonth()->toDateString());
        $to = (string) $request->query('to', $today->toDateString());
        foreach ([$from, $to] as $d) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || ! strtotime($d)) {
                Fail::bad('Dates must be YYYY-MM-DD');
            }
        }
        if ($to < $from) {
            Fail::bad('The end date is before the start date');
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            Fail::bad('Choose a period of up to a year');
        }

        return [$from, $to];
    }

    public function index(Request $request)
    {
        $this->guard($request);

        return TeamReport::report(...$this->period($request));
    }

    public function summary(Request $request)
    {
        $this->guard($request);

        return TeamReport::summary();
    }

    public function person(Request $request, string $id)
    {
        $this->guard($request);
        $u = User::where('active', true)->where('role', '<>', 'super_admin')->find(Ids::parse($id));
        if (! $u) {
            Fail::notFound('Not found');
        }

        return TeamReport::drill($u, ...$this->period($request));
    }
}

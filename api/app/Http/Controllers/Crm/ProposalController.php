<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Quote;
use App\Models\QuoteContentBlock;
use App\Models\TravelPackage;
use App\Services\ProposalPricing;
use Illuminate\Http\Request;

/** The premium proposal builder: a starting point, a live price, and saving it as an ordinary draft quote (the quote lines are worked out from it). */
class ProposalController extends Controller
{
    private function manager(Request $r): bool
    {
        return (bool) $r->user()->hasPermission('hotels.manage');
    }

    private function body(Request $request): array
    {
        $d = Fail::validate(Fail::body($request), ['proposal' => 'required|array', 'title' => 'sometimes|string|max:200', 'customer_id' => 'sometimes|nullable', 'deal_id' => 'sometimes|nullable']);

        return $d;
    }

    /** POST /quote-proposals/compute: the prices for what is on the screen, without saving. */
    public function compute(Request $request)
    {
        $p = ProposalPricing::clean(Fail::body($request)['proposal'] ?? []);

        return ['proposal' => $p, 'computed' => ProposalPricing::compute($p, $this->manager($request))];
    }

    /** GET /quote-proposals/seed?package_id&deal_id: a starting proposal, with the standard text for the destination. */
    public function seed(Request $request)
    {
        $user = $request->user();
        $deal = ($v = (string) $request->query('deal_id', '')) !== '' && ctype_digit($v) ? DealController::scopeForUser(Deal::query(), $user)->where('deals.id', (int) $v)->first() : null;
        $pkg = ($v = (string) $request->query('package_id', '')) !== '' && ctype_digit($v) ? TravelPackage::visibleTo($user)->where('packages.id', (int) $v)->first() : null;
        $destination = trim((string) ($request->query('destination') ?: ($pkg?->destination ?: ($deal?->destination ?: ''))));
        $nights = $pkg?->nights ?: ($deal?->duration_days ? max(0, (int) $deal->duration_days - 1) : 0);

        $days = [];
        if ($pkg) {
            foreach ($pkg->itineraryDays as $d) {
                $days[] = ['title' => $d->title, 'stay' => '', 'bullets' => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $d->description) ?: []))), 'media_id' => null];
            }
        }
        $lines = fn (?string $t) => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $t) ?: [])));
        $blocks = QuoteContentBlock::where('active', true)->where('is_default', true)->where(fn ($q) => $q->whereNull('destination')->orWhere('destination', $destination))->orderBy('position')->get();
        $items = fn (string $kind) => $blocks->where('kind', $kind)->flatMap(fn ($b) => $b->items ?? [])->values()->all();
        $canc = $blocks->firstWhere('kind', 'cancellation');
        $content = [
            'inclusions' => array_merge($lines($pkg?->inclusions), $items('inclusions')), 'exclusions' => array_merge($lines($pkg?->exclusions), $items('exclusions')),
            'notes' => $items('notes'), 'terms' => array_merge($items('terms'), $lines($pkg?->terms)), 'closing' => $items('closing'),
            'cancellation' => ['tiers' => $canc?->tiers ?? [], 'items' => $blocks->where('kind', 'cancellation')->flatMap(fn ($b) => $b->items ?? [])->values()->all()],
        ];
        $p = [
            'trip' => ['destination' => $destination, 'start_date' => $deal?->travel_date?->format('Y-m-d'), 'nights' => $nights, 'adults' => max(1, (int) ($deal?->adults ?? 2)),
                'children_bed' => (int) ($deal?->children ?? 0), 'children_no_bed' => 0, 'group_sizes' => [2, 4, 6]],
            'days' => $days, 'options' => [['name' => 'Option 1', 'stays' => []]],
            'land' => ['label' => 'Land package: transfers and sightseeing', 'rows' => []], 'gst_rate' => (float) ($pkg?->gst_rate ?? 5), 'selected_option' => 0,
            'content' => $content, 'cover_media_id' => null,
        ];

        return ['proposal' => ProposalPricing::clean($p), 'title' => $destination !== '' ? "$destination trip" : 'Trip proposal', 'customer_id' => $deal ? (string) $deal->customer_id : null, 'deal_id' => $deal ? (string) $deal->id : null];
    }

    /** The ordinary quote fields (and lines) a proposal turns into. */
    private function quoteFields(array $p, array $computed): array
    {
        $t = $p['trip'];

        return [
            'destination' => $t['destination'] ?: null, 'travel_date' => $t['start_date'], 'duration_days' => $t['nights'] > 0 ? $t['nights'] + 1 : null,
            'adults' => $t['adults'], 'children' => $t['children_bed'] + $t['children_no_bed'], 'lines' => ProposalPricing::quoteLines($p, $computed),
        ] + ProposalPricing::legacyFields($p);
    }

    private function call(string $method, array $payload, Request $request, ?string $id = null)
    {
        $inner = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $inner->setUserResolver(fn () => $request->user());

        return $id === null ? app(QuoteController::class)->store($inner) : app(QuoteController::class)->update($inner, $id);
    }

    private function present(Quote $q, Request $request): array
    {
        $p = ProposalPricing::clean((array) $q->proposal);

        return ['quote' => $q->toApi(true), 'proposal' => $p, 'computed' => ProposalPricing::compute($p, $this->manager($request))];
    }

    private function mine(Request $request, string $id): Quote
    {
        $q = ctype_digit($id) ? QuoteController::scopeForUser(Quote::query()->with(['customer', 'deal:id,deal_no,name', 'owner:id,name', 'lines', 'emails']), $request->user())->where('quotes.id', (int) $id)->first() : null;
        if (! $q || $q->proposal === null) {
            Fail::notFound('Proposal not found');
        }

        return $q;
    }

    public function store(Request $request)
    {
        $d = $this->body($request);
        $p = ProposalPricing::clean($d['proposal']);
        $computed = ProposalPricing::compute($p, false);
        $payload = ['customer_id' => $d['customer_id'] ?? null, 'deal_id' => $d['deal_id'] ?? null, 'title' => $d['title'] ?? (($p['trip']['destination'] ?: 'Trip').' proposal')] + $this->quoteFields($p, $computed);
        $made = $this->call('store', $payload, $request);
        $q = Quote::findOrFail((int) $made['id']);
        $q->forceFill(['proposal' => $p])->save();

        return $this->present($q->load(['customer', 'deal:id,deal_no,name', 'owner:id,name', 'lines', 'emails']), $request);
    }

    public function show(Request $request, string $id)
    {
        return $this->present($this->mine($request, $id), $request);
    }

    public function update(Request $request, string $id)
    {
        $q = $this->mine($request, $id);
        if ($q->status !== 'draft') {
            Fail::http(409, 'Only a draft can be edited. Use Revise on the quote to make a new version.');
        }
        $d = $this->body($request);
        $p = ProposalPricing::clean($d['proposal']);
        $computed = ProposalPricing::compute($p, false);
        $payload = array_filter(['title' => $d['title'] ?? null], fn ($v) => $v !== null) + $this->quoteFields($p, $computed);
        $this->call('update', $payload, $request, $id);
        $q->refresh()->forceFill(['proposal' => $p])->save();

        return $this->present($this->mine($request, $id), $request);
    }
}

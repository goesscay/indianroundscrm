<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\QuoteTemplate;
use App\Services\ProposalPricing;
use Illuminate\Http\Request;

/** Ready-made premium quotations: sales start from one and only add the customer, dates, travellers and hotels. */
class QuoteTemplateController extends Controller
{
    private function present(QuoteTemplate $t): array
    {
        $p = (array) $t->payload;
        $cover = ! empty($p['cover_media_id']) ? Media::find($p['cover_media_id']) : null;
        $opts = (array) ($p['options'] ?? []);

        return [
            'id' => (string) $t->id, 'name' => $t->name, 'destination' => $t->destination, 'tagline' => $t->tagline, 'nights' => (int) ($p['trip']['nights'] ?? 0),
            'days' => count((array) ($p['days'] ?? [])), 'options' => array_map(fn ($o) => (string) ($o['name'] ?? ''), $opts),
            'cover_url' => $cover ? $cover->toApi()['thumb_url'] : null,
        ];
    }

    public function index(Request $request)
    {
        $q = QuoteTemplate::query()->where('active', true);
        if (($v = trim((string) $request->query('destination', ''))) !== '') {
            $q->where('destination', $v);
        }

        return $q->orderBy('position')->orderBy('id')->get()->map(fn ($t) => $this->present($t))->values();
    }

    /** POST /quote-templates: save what is on the screen as a template (Operations). Hotels are not kept, only their slots. */
    public function store(Request $request)
    {
        $d = Fail::validate(Fail::body($request), ['name' => 'required|string|max:160', 'tagline' => 'nullable|string|max:200', 'proposal' => 'required|array']);
        $p = ProposalPricing::clean($d['proposal']);
        foreach ($p['options'] as &$o) {
            $o['stays'] = array_map(fn ($s) => ['meal_plan' => $s['meal_plan'], 'start_offset' => $s['start_offset'], 'nights' => $s['nights'], 'stars' => null], $o['stays']);
        }
        unset($o);
        $p['trip'] = ['destination' => $p['trip']['destination'], 'nights' => $p['trip']['nights'], 'group_sizes' => $p['trip']['group_sizes']];
        $t = QuoteTemplate::create(['name' => $d['name'], 'destination' => $p['trip']['destination'] ?: 'Any', 'tagline' => $d['tagline'] ?? null, 'payload' => $p, 'created_by' => $request->user()->id,
            'position' => (int) QuoteTemplate::max('position') + 1]);

        return $this->present($t);
    }

    public function destroy(Request $request, string $id)
    {
        $t = ctype_digit($id) ? QuoteTemplate::find((int) $id) : null;
        if (! $t) {
            Fail::notFound('Template not found');
        }
        $t->delete();

        return ['ok' => true];
    }
}

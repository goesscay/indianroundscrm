<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\QuoteContentBlock;
use App\Models\QuoteSetting;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;

/** The standard text of a quotation (inclusions, exclusions, cancellation tiers, notes, terms, closing page) and the default margins. */
class QuoteContentController extends Controller
{
    private function row(QuoteContentBlock $b): array
    {
        return ['id' => (string) $b->id, 'kind' => $b->kind, 'title' => $b->title, 'destination' => $b->destination, 'items' => $b->items ?? [], 'tiers' => $b->tiers ?? [],
            'is_default' => (bool) $b->is_default, 'active' => (bool) $b->active, 'position' => (int) $b->position];
    }

    private function find(string $id): QuoteContentBlock
    {
        $b = ctype_digit($id) ? QuoteContentBlock::find((int) $id) : null;
        if (! $b) {
            Fail::notFound('Not found');
        }

        return $b;
    }

    public function index(Request $request)
    {
        $q = QuoteContentBlock::query();
        if (($v = (string) $request->query('kind', '')) !== '') {
            $q->where('kind', $v);
        }

        return $q->orderBy('kind')->orderBy('position')->orderBy('title')->get()->map(fn ($b) => $this->row($b))->values();
    }

    private function validated(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $d = Fail::validate(Fail::body($request), [
            'kind' => "$req|in:".implode(',', QuoteContentBlock::KINDS), 'title' => "$req|string|max:160", 'destination' => 'nullable|string|max:120',
            'items' => 'nullable|array|max:80', 'items.*' => 'string|max:800',
            'tiers' => 'nullable|array|max:12', 'tiers.*.label' => 'required|string|max:160', 'tiers.*.percent' => 'required|numeric|min:0|max:100',
            'is_default' => 'sometimes|boolean', 'active' => 'sometimes|boolean', 'position' => 'sometimes|integer|min:0',
        ]);
        if (isset($d['items'])) {
            $d['items'] = array_values(array_filter(array_map('trim', $d['items']), fn ($x) => $x !== ''));
        }
        if (array_key_exists('destination', $d)) {
            $d['destination'] = trim((string) $d['destination']) ?: null;
        }
        if (isset($d['tiers'])) {
            $d['tiers'] = array_map(fn ($t) => ['label' => trim($t['label']), 'percent' => (float) $t['percent']], $d['tiers']);
        }

        return $d;
    }

    public function store(Request $request)
    {
        $b = QuoteContentBlock::create($this->validated($request, false));
        ActivityLogger::log($request->user(), 'create_quote_content', 'quote_content', $b->id, null, ['kind' => $b->kind, 'title' => $b->title]);

        return $this->row($b->fresh());
    }

    public function update(Request $request, string $id)
    {
        $b = $this->find($id);
        $b->fill($this->validated($request, true))->save();
        ActivityLogger::log($request->user(), 'update_quote_content', 'quote_content', $b->id, null, ['kind' => $b->kind, 'title' => $b->title]);

        return $this->row($b->fresh());
    }

    public function destroy(Request $request, string $id)
    {
        $b = $this->find($id);
        $b->delete();
        ActivityLogger::log($request->user(), 'delete_quote_content', 'quote_content', $id, ['title' => $b->title], null);

        return ['success' => true];
    }

    // ---- margins

    public function settings()
    {
        return ['margin_default' => QuoteSetting::read('margin_default', ['type' => 'percent', 'value' => 0]), 'margin_destinations' => (object) QuoteSetting::read('margin_destinations', [])];
    }

    public function saveSettings(Request $request)
    {
        $d = Fail::validate(Fail::body($request), [
            'margin_default.type' => 'required|in:percent,amount', 'margin_default.value' => 'required|numeric|min:0|max:10000000',
            'margin_destinations' => 'nullable|array|max:200', 'margin_destinations.*.type' => 'required|in:percent,amount', 'margin_destinations.*.value' => 'required|numeric|min:0|max:10000000',
        ]);
        QuoteSetting::write('margin_default', ['type' => $d['margin_default']['type'], 'value' => (float) $d['margin_default']['value']]);
        $dest = [];
        foreach (($d['margin_destinations'] ?? []) as $name => $m) {
            $dest[trim((string) $name)] = ['type' => $m['type'], 'value' => (float) $m['value']];
        }
        QuoteSetting::write('margin_destinations', $dest);
        ActivityLogger::log($request->user(), 'update_quote_margins', 'quote_settings', 'margins', null, ['default' => $d['margin_default'], 'destinations' => count($dest)]);

        return $this->settings();
    }
}

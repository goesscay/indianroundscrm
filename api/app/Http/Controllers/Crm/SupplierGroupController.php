<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierGroup;
use App\Support\ActivityLogger;
use App\Support\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Named sets of suppliers ("Vietnam desk"), used to pick several suppliers at once when sending a supplier mail. */
class SupplierGroupController extends Controller
{
    private function row(SupplierGroup $g): array
    {
        return [
            'id' => (string) $g->id, 'name' => $g->name, 'destination' => $g->destination ?? '',
            'supplier_ids' => $g->suppliers->pluck('id')->map(fn ($i) => (string) $i)->values()->all(),
        ];
    }

    public function index()
    {
        return SupplierGroup::with('suppliers:id')->orderBy('name')->get()->map(fn ($g) => $this->row($g))->values();
    }

    private function validated(Request $request, ?SupplierGroup $g = null): array
    {
        $b = Fail::validate(Fail::body($request), [
            'name' => 'required|string|max:120', 'destination' => 'nullable|string|max:255',
            'supplier_ids' => 'nullable|array', 'supplier_ids.*' => 'required',
        ]);
        $name = trim($b['name']);
        $dup = SupplierGroup::whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($g, fn ($q) => $q->where('id', '<>', $g->id))->exists();
        if ($dup) {
            Fail::bad("A group called \"$name\" already exists");
        }
        $ids = collect($b['supplier_ids'] ?? [])->map(fn ($i) => Ids::parse((string) $i))->filter()->unique()->values();
        $valid = Supplier::whereIn('id', $ids)->pluck('id')->all();

        return [$name, trim((string) ($b['destination'] ?? '')), $valid];
    }

    public function store(Request $request)
    {
        [$name, $dest, $ids] = $this->validated($request);
        $g = DB::transaction(function () use ($name, $dest, $ids) {
            $g = SupplierGroup::create(['name' => $name, 'destination' => $dest]);
            $g->suppliers()->sync($ids);

            return $g;
        });
        ActivityLogger::log($request->user(), 'create_supplier_group', 'supplier_group', $g->id, null, ['name' => $name, 'members' => count($ids)]);

        return $this->row($g->load('suppliers:id'));
    }

    public function update(Request $request, string $gid)
    {
        $g = SupplierGroup::find(Ids::parse($gid));
        if (! $g) {
            Fail::notFound('Group not found');
        }
        [$name, $dest, $ids] = $this->validated($request, $g);
        DB::transaction(function () use ($g, $name, $dest, $ids) {
            $g->fill(['name' => $name, 'destination' => $dest])->save();
            $g->suppliers()->sync($ids);
        });
        ActivityLogger::log($request->user(), 'update_supplier_group', 'supplier_group', $g->id, null, ['name' => $name, 'members' => count($ids)]);

        return $this->row($g->load('suppliers:id'));
    }

    public function destroy(Request $request, string $gid)
    {
        $g = SupplierGroup::find(Ids::parse($gid));
        if (! $g) {
            Fail::notFound('Group not found');
        }
        $g->delete();                                   // the suppliers stay; only the grouping goes
        ActivityLogger::log($request->user(), 'delete_supplier_group', 'supplier_group', $gid, ['name' => $g->name], null);

        return ['success' => true];
    }
}

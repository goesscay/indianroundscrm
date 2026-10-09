<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Services\SupplierPortal\PortalAuth;
use App\Support\ActivityLogger;
use App\Support\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Who at a supplier may sign in to the supplier portal. Managed by Operations / Admin. */
class SupplierPortalAdminController extends Controller
{
    private function supplier(string $sid): Supplier
    {
        $s = Supplier::find(Ids::parse($sid));
        if (! $s) {
            Fail::notFound('Supplier not found');
        }

        return $s;
    }

    private function present(SupplierUser $u): array
    {
        return [
            'id' => (string) $u->id, 'name' => $u->name, 'email' => $u->email, 'active' => (bool) $u->active,
            'last_login_at' => $u->last_login_at?->utc()->toIso8601String(),
        ];
    }

    /** POST /suppliers/portal-bulk {ids: [...], enabled: bool} */
    public function bulk(Request $request)
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['required'], 'enabled' => ['required', 'boolean']]);
        $changed = 0;
        $skipped = [];
        foreach (Supplier::whereIn('id', array_map(fn ($i) => Ids::parse((string) $i), $d['ids']))->get() as $s) {
            if ($d['enabled']) {
                if (! \App\Services\SupplierPortal\PortalMail::ensureUser($s)) {
                    $skipped[] = ['id' => (string) $s->id, 'name' => $s->name, 'reason' => trim((string) $s->email) === '' ? 'No e-mail address' : 'Its e-mail already belongs to another supplier\'s portal account'];
                    continue;
                }
                $s->forceFill(['portal_enabled' => true])->save();
            } else {
                $s->forceFill(['portal_enabled' => false])->save();
                DB::table('supplier_sessions')->whereIn('supplier_user_id', SupplierUser::where('supplier_id', $s->id)->select('id'))->delete();
            }
            $changed++;
        }
        ActivityLogger::log($request->user(), $d['enabled'] ? 'enable_supplier_portal' : 'disable_supplier_portal', 'supplier', 'bulk', null, ['count' => $changed]);

        return ['changed' => $changed, 'skipped' => $skipped];
    }

    public function index(string $sid)
    {
        $s = $this->supplier($sid);

        return SupplierUser::where('supplier_id', $s->id)->orderBy('id')->get()->map(fn ($u) => $this->present($u))->values();
    }

    public function store(Request $request, string $sid)
    {
        $s = $this->supplier($sid);
        $d = $request->validate(['name' => ['nullable', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']]);
        $email = PortalAuth::normalise($d['email']);
        if (SupplierUser::whereRaw('lower(email) = ?', [$email])->exists()) {
            Fail::http(409, 'That e-mail address already has portal access (for this or another supplier).');
        }
        $u = SupplierUser::create(['supplier_id' => $s->id, 'name' => trim((string) ($d['name'] ?? '')) ?: $s->name, 'email' => $email, 'active' => true]);
        ActivityLogger::log($request->user(), 'add_supplier_portal_user', 'supplier', (string) $s->id, null, ['email' => $email]);

        return $this->present($u);
    }

    public function update(Request $request, string $sid, string $uid)
    {
        $s = $this->supplier($sid);
        $u = SupplierUser::where('supplier_id', $s->id)->find(Ids::parse($uid));
        if (! $u) {
            Fail::notFound('Portal user not found');
        }
        $d = $request->validate(['active' => ['sometimes', 'boolean'], 'name' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $u->fill($d)->save();
        if (array_key_exists('active', $d) && ! $d['active']) {
            DB::table('supplier_sessions')->where('supplier_user_id', $u->id)->delete();
        }
        ActivityLogger::log($request->user(), 'update_supplier_portal_user', 'supplier', (string) $s->id, null, ['email' => $u->email] + $d);

        return $this->present($u);
    }

    public function destroy(Request $request, string $sid, string $uid)
    {
        $s = $this->supplier($sid);
        $u = SupplierUser::where('supplier_id', $s->id)->find(Ids::parse($uid));
        if (! $u) {
            Fail::notFound('Portal user not found');
        }
        $u->delete();
        ActivityLogger::log($request->user(), 'remove_supplier_portal_user', 'supplier', (string) $s->id, null, ['email' => $u->email]);

        return ['success' => true];
    }
}

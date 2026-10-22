<?php

namespace App\Http\Controllers;

use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Ids;
use App\Support\PasswordPolicy;
use App\Support\Permissions;
use App\Support\SecurityLogger;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /** Destinations typed by hand: trimmed, single spaces, no blanks, no repeats (any capitalisation), order kept. @return list<string> */
    private static function places(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $v = trim(preg_replace('/\s+/', ' ', (string) $v) ?? '');
            if ($v !== '' && ! in_array(mb_strtolower($v), array_map('mb_strtolower', $out), true)) {
                $out[] = mb_substr($v, 0, 120);
            }
        }

        return array_slice($out, 0, 30);
    }

    public function index(Request $request)
    {
        $users = User::orderBy('id')->get();
        if (! $request->user()->isSuperAdmin()) {
            $users = $users->reject(fn (User $u) => $u->role === 'super_admin')->values();      // Super Admins are invisible to everyone else
        }

        return $users->each(
            fn (User $u) => $u->setAttribute('assignable', $u->hasPermission('leads.assignable'))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'name' => ['required', 'string'],
            'role' => ['required', 'string'],
            'staff_code' => ['nullable', 'string', 'max:50'],
            'consultant_code' => ['nullable', 'string'],
            'destinations' => ['nullable', 'array', 'max:30'],
            'destinations.*' => ['nullable', 'string', 'max:120'],
            'default_destination' => ['nullable', 'string', 'max:120'],
        ]);
        $email = strtolower($data['email']);
        if (User::where('email', $email)->exists()) {
            abort(400, 'Email already exists');
        }
        if (! Permissions::roleExists($data['role'])) {
            abort(400, 'Invalid role');
        }
        $this->assertMayAssign($request, $data['role']);
        PasswordPolicy::validate($data['password']);

        $user = User::create([
            'email' => $email,
            'password' => $data['password'],
            'name' => $data['name'],
            'role' => $data['role'],
            'staff_code' => $data['staff_code'] ?? '',
            'consultant_code' => strtoupper(mb_substr($data['consultant_code'] ?? '', 0, 2)),
            'destinations' => self::places($data['destinations'] ?? (isset($data['default_destination']) ? [$data['default_destination']] : [])),
            'active' => true,
            'account_status' => 'active',
        ]);
        ActivityLogger::log($request->user(), 'create_user', 'user', $user->id, null, ['email' => $email, 'role' => $data['role']]);

        return $user->fresh();
    }

    public function update(Request $request, string $uid)
    {
        $id = Ids::parse($uid);
        $request->validate([
            'name' => ['sometimes', 'nullable', 'string'],
            'role' => ['sometimes', 'nullable', 'string'],
            'staff_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'consultant_code' => ['sometimes', 'nullable', 'string'],
            'destinations' => ['sometimes', 'nullable', 'array', 'max:30'],
            'destinations.*' => ['nullable', 'string', 'max:120'],
            'default_destination' => ['sometimes', 'nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'nullable', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        $updates = array_filter(
            $request->only(['name', 'role', 'staff_code', 'consultant_code', 'active', 'password', 'email', 'mobile']),
            fn ($v) => $v !== null
        );
        if (isset($updates['role']) && ! Permissions::roleExists($updates['role'])) {
            abort(400, 'Invalid role');
        }
        if ($request->has('destinations')) {
            // an empty list (or null) means "none", so it is handled apart from the null-skipping filter above
            $updates['destinations'] = self::places((array) $request->input('destinations'));
        } elseif ($request->has('default_destination')) {
            $updates['destinations'] = self::places([$request->input('default_destination')]);   // the older single-value field
        }
        if (isset($updates['consultant_code'])) {
            $updates['consultant_code'] = strtoupper(mb_substr($updates['consultant_code'], 0, 2));
        }
        if (! $updates) {
            abort(400, 'No fields to update');
        }
        $user = User::find($id);
        if (! $user) {
            abort(404, 'User not found');
        }

        if (isset($updates['email'])) {
            $updates['email'] = strtolower(trim($updates['email']));
            if (User::where('email', $updates['email'])->where('id', '!=', $user->id)->exists()) {
                abort(400, 'Email already exists');
            }
        }

        $this->assertMayTouch($request, $user);
        if (isset($updates['role'])) {
            $this->assertMayAssign($request, $updates['role']);
        }
        $oldRole = $user->role;
        $roleChanged = isset($updates['role']) && $updates['role'] !== $oldRole;
        if ($roleChanged) {
            if ((int) $user->id === (int) $request->user()->id) {
                abort(403, 'You cannot change your own role');
            }
            if (in_array($oldRole, ['admin', 'super_admin'], true)) {
                $this->assertNotLastAdmin($user, 'demote', $updates['role']);
            }
        }
        if (array_key_exists('active', $updates) && ! $updates['active']) {
            $this->assertNotLastAdmin($user, 'disable');
        }

        $logged = $updates;
        if (isset($updates['password'])) {
            PasswordPolicy::validate($updates['password']);
            $user->password_changed_at = now();
            $user->invalidateSessions('password_changed_by_admin'); // bumps token_version
            unset($logged['password']);
            $logged['password_changed'] = true;
        }
        $user->fill($updates)->save();
        ActivityLogger::log($request->user(), 'update_user', 'user', $user->id, null, $logged);
        if ($roleChanged) {
            ActivityLogger::log($request->user(), 'change_user_role', 'user', $user->id, ['role' => $oldRole], ['role' => $user->role]);
        }

        return $user->fresh();
    }

    public function destroy(Request $request, string $uid)
    {
        $id = Ids::parse($uid);
        if ($id === (int) $request->user()->id) {
            abort(400, 'Cannot delete yourself');
        }
        $user = User::find($id);
        if (! $user) {
            abort(404, 'User not found');
        }
        $this->assertMayTouch($request, $user);
        $this->assertNotLastAdmin($user, 'delete');
        $user->delete();
        ActivityLogger::log($request->user(), 'delete_user', 'user', $id);

        return ['success' => true];
    }

    public function forceReset(Request $request, string $uid)
    {
        $user = $this->target($request, $uid);
        $reason = (string) $request->input('reason', '');
        $user->account_status = 'password_reset_required';
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->invalidateSessions('admin_force_reset');
        $user->save();
        SecurityLogger::log($request->user(), 'admin_force_reset', $request, ['target_user' => $user->email, 'reason' => $reason]);
        ActivityLogger::log($request->user(), 'admin_force_reset', 'user', $user->id, null, ['reason' => $reason]);

        return ['success' => true];
    }

    public function unlock(Request $request, string $uid)
    {
        $user = $this->target($request, $uid);
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        if ($user->account_status === 'locked') {
            $user->account_status = 'active';
        }
        $user->save();
        SecurityLogger::log($request->user(), 'admin_unlock', $request, ['target_user' => $user->email]);

        return ['success' => true];
    }

    public function status(Request $request, string $uid)
    {
        $data = $request->validate(['account_status' => ['required', 'string']]);
        if (! in_array($data['account_status'], User::STATUSES, true)) {
            $list = implode(', ', User::STATUSES);
            abort(400, "Invalid status. Must be one of: {$list}");
        }
        $user = $this->target($request, $uid);
        if ((int) $user->id === (int) $request->user()->id && $data['account_status'] !== 'active') {
            abort(400, 'You cannot deactivate/suspend yourself.');
        }
        if (! in_array($data['account_status'], ['active', 'password_reset_required'], true)) {
            $this->assertNotLastAdmin($user, 'disable');
        }
        $user->account_status = $data['account_status'];
        if ($data['account_status'] === 'inactive') {
            $user->active = false;                    // left the company: also off the pick-lists, chat and reports
        } elseif ($data['account_status'] === 'active') {
            $user->active = true;
        }
        if (in_array($data['account_status'], ['inactive', 'suspended'], true)) {
            $user->invalidateSessions('admin_status_change');
        }
        if ($data['account_status'] === 'active') {
            $user->failed_login_attempts = 0;
            $user->locked_until = null;
        }
        $user->save();
        SecurityLogger::log($request->user(), 'admin_status_change', $request, [
            'target_user' => $user->email, 'new_status' => $data['account_status'],
        ]);

        return ['success' => true];
    }

    /** What a person still has on their plate, so it can be handed over when they leave. @return array<string,int> */
    private function workload(User $u): array
    {
        return [
            'leads' => \DB::table('leads')->where('staff_id', $u->id)->whereNull('archived_at')->whereIn('lead_status', ['New Lead', 'In Progress', 'Quote Sent to Customer'])->count(),
            'activities' => \DB::table('activities')->where('assigned_to', $u->id)->whereNull('completed_at')->count(),
            'trip_tasks' => \DB::table('trip_tasks')->where('assigned_to', $u->id)->whereIn('status', ['pending', 'in_progress'])->count(),
            'deals' => \DB::table('deals')->where('owner_id', $u->id)->whereIn('stage', ['enquiry', 'quoted', 'negotiation'])->count(),
            'allocations' => \DB::table('allocations')->where('consultant_id', $u->id)->count(),
        ];
    }

    public function workloadOf(Request $request, string $uid)
    {
        return $this->workload($this->target($request, $uid));
    }

    /** POST /users/{id}/deactivate {reassign_to?, reason?}: the person has left. They cannot sign in, and (optionally) their open work goes to someone else. */
    public function deactivate(Request $request, string $uid)
    {
        $actor = $request->user();
        $user = $this->target($request, $uid);
        $d = $request->validate(['reassign_to' => ['nullable'], 'reason' => ['nullable', 'string', 'max:300']]);
        if ((int) $user->id === (int) $actor->id) {
            abort(400, 'You cannot deactivate yourself.');
        }
        $this->assertNotLastAdmin($user, 'deactivate');
        $to = null;
        if (! empty($d['reassign_to'])) {
            $to = User::find(Ids::parse((string) $d['reassign_to']));
            if (! $to || (int) $to->id === (int) $user->id || ! $to->active || $to->account_status !== 'active' || ! $to->hasPermission('leads.assignable')) {
                abort(422, 'Choose an active sales consultant to take over their work.');
            }
        }
        $before = $this->workload($user);
        $moved = ['leads' => 0, 'activities' => 0, 'trip_tasks' => 0, 'deals' => 0];
        \DB::transaction(function () use ($user, $to, $actor, &$moved, $d) {
            if ($to) {
                $code = $to->consultant_code ?: ($to->staff_code ?: '');
                foreach (\App\Models\Lead::where('staff_id', $user->id)->whereNull('archived_at')->whereIn('lead_status', ['New Lead', 'In Progress', 'Quote Sent to Customer'])->get() as $l) {
                    $prev = ['staff_id' => (string) $l->staff_id, 'staff_name' => $l->staff_name, 'staff_code' => $l->staff_code];
                    $l->fill(['staff_id' => $to->id, 'staff_name' => $to->name, 'staff_code' => $code, 'updated_by' => $actor->email])->save();
                    ActivityLogger::log($actor, 'reassign_lead', 'lead', $l->lead_id, $prev, ['staff_id' => (string) $to->id, 'staff_name' => $to->name, 'reason' => 'Handed over: '.$user->name.' left']);
                    $moved['leads']++;
                }
                $moved['activities'] = \DB::table('activities')->where('assigned_to', $user->id)->whereNull('completed_at')->update(['assigned_to' => $to->id]);
                $moved['trip_tasks'] = \DB::table('trip_tasks')->where('assigned_to', $user->id)->whereIn('status', ['pending', 'in_progress'])->update(['assigned_to' => $to->id]);
                $moved['deals'] = \DB::table('deals')->where('owner_id', $user->id)->whereIn('stage', ['enquiry', 'quoted', 'negotiation'])->update(['owner_id' => $to->id]);
            }
            \DB::table('allocations')->where('consultant_id', $user->id)->delete();          // new leads must not be auto-assigned to someone who has left
            $user->active = false;
            $user->account_status = 'inactive';
            $user->invalidateSessions('deactivated');
            $user->save();
        });
        ActivityLogger::log($actor, 'deactivate_user', 'user', $user->id, ['active' => true], ['active' => false, 'reassigned_to' => $to?->name, 'moved' => $moved, 'reason' => $d['reason'] ?? null]);
        SecurityLogger::log($actor, 'admin_status_change', $request, ['target_user' => $user->email, 'new_status' => 'inactive']);

        return ['success' => true, 'moved' => $moved, 'left_with_them' => $to ? [] : $before];
    }

    public function reactivate(Request $request, string $uid)
    {
        $actor = $request->user();
        $user = $this->target($request, $uid);
        $user->active = true;
        $user->account_status = 'active';
        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();
        ActivityLogger::log($actor, 'reactivate_user', 'user', $user->id, ['active' => false], ['active' => true]);
        SecurityLogger::log($actor, 'admin_status_change', $request, ['target_user' => $user->email, 'new_status' => 'active']);

        return ['success' => true];
    }

    public function logoutUser(Request $request, string $uid)
    {
        $user = $this->target($request, $uid);
        $user->last_logout_at = now();
        $user->invalidateSessions('admin_kick_all');
        $user->save();
        SecurityLogger::log($request->user(), 'admin_session_terminated', $request, ['target_user' => $user->email]);

        return ['success' => true];
    }

    public function securityAuditLog(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 500), 1), 5000);
        $q = SecurityAuditLog::query();
        if ($e = $request->query('event')) {
            $q->where('event', $e);
        }
        if ($ue = $request->query('user_email')) {
            $q->where('user_email', $ue);
        }

        $q->orderByDesc('timestamp')->orderByDesc('id');
        if (\App\Support\Paging::requested($request)) {
            return \App\Support\Paging::paginate($request, $q, fn ($m) => $m->toArray());
        }

        return $q->limit($limit)->get();
    }

    /**
     * Refuse to remove (delete/demote/disable) the only remaining active Super Admin, or the last active admin of any kind.
     * Moving an admin up to Super Admin ($newRole) does not remove an admin.
     */
    private function assertNotLastAdmin(User $user, string $what, ?string $newRole = null): void
    {
        if (! in_array($user->role, ['admin', 'super_admin'], true)) {
            return;
        }
        if ($user->role === 'admin' && $newRole === 'super_admin') {
            return;
        }
        $pool = $user->role === 'super_admin' ? ['super_admin'] : ['admin', 'super_admin'];
        $others = User::whereIn('role', $pool)->where('id', '!=', $user->id)
            ->where('active', true)->whereIn('account_status', ['active', 'password_reset_required'])->count();
        if ($others === 0) {
            abort(409, $user->role === 'super_admin' ? "Cannot {$what} the last active Super Admin" : "Cannot {$what} the last active admin");
        }
    }

    /** Super Admins can only be changed, reset, disabled or removed by a Super Admin. */
    private function assertMayTouch(Request $request, User $target): void
    {
        if ($target->role === 'super_admin' && ! $request->user()->isSuperAdmin()) {
            abort(404, 'User not found');                  // not even visible to them
        }
    }

    /** Only a Super Admin can give someone the Super Admin role. */
    private function assertMayAssign(Request $request, string $role): void
    {
        if ($role === 'super_admin' && ! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a Super Admin can make someone a Super Admin.');
        }
    }

    private function target(Request $request, string $uid): User
    {
        $user = User::find(Ids::parse($uid));
        if (! $user) {
            abort(404, 'User not found');
        }
        $this->assertMayTouch($request, $user);

        return $user;
    }
}

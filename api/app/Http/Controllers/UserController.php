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

    public function index()
    {
        return User::orderBy('id')->get()->each(
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
            abort(403, 'Only a Super Admin can change a Super Admin.');
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

<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSession;
use App\Support\SecurityLogger;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function mine(Request $request)
    {
        $current = $request->session()->get('jti');
        $rows = UserSession::where('user_id', $request->user()->id)->where('revoked', false)
            ->orderByDesc('last_active_at')->limit(50)->get();

        return $rows->map(fn ($r) => $this->present($r) + ['is_current' => $r->jti === $current])->values();
    }

    public function all(Request $request)
    {
        $q = UserSession::query();
        if ($uid = $request->query('user_id')) {
            $q->where('user_id', (int) $uid);
        }
        if (filter_var($request->query('active_only', 'true'), FILTER_VALIDATE_BOOLEAN)) {
            $q->where('revoked', false);
        }
        $rows = $q->orderByDesc('last_active_at')->limit(500)->get();
        $users = User::whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');

        return $rows->map(function ($r) use ($users) {
            $u = $users[$r->user_id] ?? null;

            return $this->present($r) + ['email' => $u?->email, 'name' => $u?->name, 'role' => $u?->role];
        })->values();
    }

    public function revoke(Request $request, string $jti)
    {
        $user = $request->user();
        $sess = UserSession::where('jti', $jti)->first();
        if (! $sess) {
            abort(404, 'Session not found');
        }
        $own = (int) $sess->user_id === (int) $user->id;
        if (! $own && ! $user->hasPermission('users.manage')) {
            abort(403, 'Not your session');
        }
        $sess->revoke($own ? 'user_revoke' : 'admin_revoke');
        SecurityLogger::log($user, 'session_revoked', $request, ['target_jti' => $jti]);

        return ['success' => true];
    }

    private function present(UserSession $s): array
    {
        return $s->toArray();
    }
}

<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\CallScript;
use App\Support\ActivityLogger;
use App\Support\Ids;
use Illuminate\Http\Request;

/**
 * Call scripts for the sales team. A script is either a team default (admin) or a person's own version; for a destination a
 * person's own version wins over the team default. The page decides which to show; the API stores and returns what the caller may see.
 */
class CallScriptController extends Controller
{
    private function mayEditTeam(Request $request): bool
    {
        return $request->user()->hasPermission('leads.scripts');
    }

    private static function place(?string $v): ?string
    {
        $v = trim(preg_replace('/\s+/', ' ', (string) $v) ?? '');

        return $v === '' ? null : mb_substr($v, 0, 120);
    }

    /** GET /scripts: the team defaults and the caller's own scripts. */
    public function index(Request $request)
    {
        $me = $request->user()->id;

        return CallScript::where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $me))
            ->orderByRaw('destination is null desc')->orderBy('destination')->orderByRaw('user_id is null')->get()
            ->map(fn (CallScript $s) => $s->toApi())->values();
    }

    /** POST /scripts {destination?, title?, body, scope?: mine|team}: saves the caller's version for that destination (or the team default). */
    public function store(Request $request)
    {
        $user = $request->user();
        $d = Fail::validate(Fail::body($request), [
            'destination' => 'nullable|string|max:120',
            'title' => 'nullable|string|max:160',
            'body' => 'required|string|max:8000',
            'scope' => 'nullable|in:mine,team',
        ]);
        $team = ($d['scope'] ?? 'mine') === 'team';
        if ($team && ! $this->mayEditTeam($request)) {
            Fail::forbidden('Only people who manage call scripts can change the team defaults');
        }
        $dest = self::place($d['destination'] ?? null);
        $q = CallScript::query();
        $team ? $q->whereNull('user_id') : $q->where('user_id', $user->id);
        $dest === null ? $q->whereNull('destination') : $q->whereRaw('lower(destination) = ?', [mb_strtolower($dest)]);
        $row = $q->first() ?? new CallScript(['user_id' => $team ? null : $user->id, 'destination' => $dest]);
        $row->fill(['title' => trim((string) ($d['title'] ?? '')) ?: ($dest ? "$dest call" : 'General call'), 'body' => $d['body']])->save();
        ActivityLogger::log($user, $team ? 'save_team_script' : 'save_own_script', 'call_script', (string) $row->id, null, ['destination' => $dest]);

        return $row->toApi();
    }

    /** DELETE /scripts/{id}: the caller's own script (back to the team default), or a team script for people who manage them. */
    public function destroy(Request $request, string $id)
    {
        $row = CallScript::find(Ids::parse($id));
        if (! $row) {
            Fail::notFound('Script not found');
        }
        $own = $row->user_id !== null && (int) $row->user_id === (int) $request->user()->id;
        if (! $own && ! ($row->user_id === null && $this->mayEditTeam($request))) {
            Fail::forbidden('You cannot delete this script');
        }
        $row->delete();
        ActivityLogger::log($request->user(), $own ? 'delete_own_script' : 'delete_team_script', 'call_script', (string) $id, null, ['destination' => $row->destination]);

        return ['success' => true];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Ids;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function permissions()
    {
        return Permissions::catalog();
    }

    public function index()
    {
        $counts = User::query()->selectRaw('role, count(*) as c')->groupBy('role')->pluck('c', 'role');

        return Role::orderByDesc('is_system')->orderBy('name')->get()
            ->map(fn (Role $r) => $this->present($r, (int) ($counts[$r->slug] ?? 0)))->values();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'nullable', 'array'],
        ]);
        $name = trim($data['name']);
        $slug = Str::slug($name);
        if ($name === '' || $slug === '') {
            throw ValidationException::withMessages(['name' => 'Role name must contain letters or numbers.']);
        }
        if (Role::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->orWhere('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => 'A role with this name already exists.']);
        }
        $perms = $this->permissionList($request);

        $role = Role::create(['name' => $name, 'slug' => $slug, 'description' => $data['description'] ?? null, 'is_system' => false]);
        $role->syncPermissions($perms);
        ActivityLogger::log($request->user(), 'create_role', 'role', $role->id, null, [
            'name' => $name, 'slug' => $slug, 'permissions' => $perms,
        ]);

        return $this->present($role->fresh(), 0);
    }

    public function update(Request $request, string $id)
    {
        $role = $this->find($id);
        if ($role->isAdmin()) {
            abort(403, 'The admin role cannot be modified');
        }
        $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'nullable', 'array'],
        ]);
        $before = ['name' => $role->name, 'description' => $role->description, 'permissions' => $role->permissionKeys()];

        if ($request->has('name') && trim((string) $request->input('name')) !== $role->name) {
            if ($role->is_system) {
                abort(403, 'System role names cannot be changed');
            }
            $name = trim((string) $request->input('name'));
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Role name is required.']);
            }
            if (Role::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->where('id', '!=', $role->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'A role with this name already exists.']);
            }
            $role->name = $name; // slug is immutable
        }
        if ($request->has('description') && $request->input('description') !== $role->description) {
            if ($role->is_system) {
                abort(403, 'System role descriptions cannot be changed');
            }
            $role->description = $request->input('description');
        }
        $perms = $request->has('permissions') ? $this->permissionList($request) : null;

        $role->save();
        if ($perms !== null) {
            $role->syncPermissions($perms);
        }
        \App\Support\Permissions::flush();
        ActivityLogger::log($request->user(), 'update_role', 'role', $role->id, $before, [
            'name' => $role->name, 'description' => $role->description, 'permissions' => $role->permissionKeys(),
        ]);

        return $this->present($role->fresh(), $role->userCount());
    }

    public function destroy(Request $request, string $id)
    {
        $role = $this->find($id);
        if ($role->is_system) {
            abort(403, 'System roles cannot be deleted');
        }
        $n = $role->userCount();
        if ($n > 0) {
            abort(409, "This role is assigned to {$n} user".($n === 1 ? '' : 's').'. Reassign them first.');
        }
        $before = ['name' => $role->name, 'slug' => $role->slug, 'permissions' => $role->permissionKeys()];
        $role->delete(); // role_permissions cascade
        Permissions::flush();
        ActivityLogger::log($request->user(), 'delete_role', 'role', $id, $before);

        return ['ok' => true];
    }

    public function users(string $id)
    {
        $role = $this->find($id);

        return User::where('role', $role->slug)->orderBy('name')->get(['id', 'name', 'email', 'active'])
            ->map(fn (User $u) => ['id' => (string) $u->id, 'name' => $u->name, 'email' => $u->email, 'active' => (bool) $u->active])
            ->values();
    }

    // ------------------------------------------------------------------ helpers

    private function find(string $id): Role
    {
        $role = Role::find(Ids::parse($id));
        if (! $role) {
            abort(404, 'Role not found');
        }

        return $role;
    }

    /** @return list<string> validated, de-duplicated permission keys from the request (invalid key -> 422). */
    private function permissionList(Request $request): array
    {
        $raw = $request->input('permissions', []) ?? [];
        $out = [];
        foreach ($raw as $k) {
            if (! is_string($k) || ! Permissions::isValid($k)) {
                throw ValidationException::withMessages(['permissions' => 'Invalid permission: '.(is_scalar($k) ? $k : 'value')]);
            }
            $out[$k] = true;
        }

        return array_keys($out);
    }

    private function present(Role $r, int $userCount): array
    {
        return [
            'id' => (string) $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'description' => $r->description,
            'is_system' => (bool) $r->is_system,
            'user_count' => $userCount,
            'permissions' => $r->permissionKeys(),
            'all_permissions' => $r->isAdmin(),
        ];
    }
}

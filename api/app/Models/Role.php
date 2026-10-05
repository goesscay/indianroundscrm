<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function isAdmin(): bool
    {
        return $this->slug === 'admin';
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        if ($this->isAdmin()) {
            return Permissions::keys();
        }
        $have = DB::table('role_permissions')->where('role_id', $this->id)->pluck('permission')->all();

        return array_values(array_intersect(Permissions::keys(), $have));
    }

    /** @param list<string> $keys */
    public function syncPermissions(array $keys): void
    {
        DB::table('role_permissions')->where('role_id', $this->id)->delete();
        $rows = array_map(fn ($k) => ['role_id' => $this->id, 'permission' => $k], array_values(array_unique($keys)));
        if ($rows) {
            DB::table('role_permissions')->insert($rows);
        }
        Permissions::flush();
    }

    public function userCount(): int
    {
        return User::where('role', $this->slug)->count();
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Support\Permissions;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, StringId;

    public const ROLES = ['admin', 'sales', 'operations']; // system role slugs; custom roles live in the `roles` table
    public const STATUSES = ['active', 'inactive', 'suspended', 'locked', 'password_reset_required'];

    protected $fillable = [
        'name', 'email', 'password', 'role', 'staff_code', 'consultant_code', 'mobile', 'active',
        'account_status', 'token_version', 'failed_login_attempts', 'locked_until',
        'last_login_at', 'last_logout_at', 'password_changed_at',
    ];

    protected $hidden = ['password', 'remember_token', 'token_version'];

    protected $appends = ['role_name'];

    public function getRoleNameAttribute(): string
    {
        return Permissions::roleName($this->role);
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'token_version' => 'integer',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'last_logout_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasRole(string ...$roles): bool
    {
        $roles = array_map(fn ($r) => $r === 'ops' ? 'operations' : $r, $roles);

        return in_array($this->role, $roles, true);
    }

    /** Admin bypasses every check; other roles are resolved from role_permissions. Unknown keys are denied. */
    public function hasPermission(string $key): bool
    {
        if (! Permissions::isValid($key)) {
            return false;
        }
        if ($this->role === 'admin') {
            return true;
        }
        // full edit implies limited edit
        if ($key === 'leads.edit_limited' && in_array('leads.edit', Permissions::forRole($this->role), true)) {
            return true;
        }

        return in_array($key, Permissions::forRole($this->role), true);
    }

    /** ANY-of. */
    public function hasAnyPermission(string ...$keys): bool
    {
        foreach ($keys as $k) {
            if ($this->hasPermission($k)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        $keys = Permissions::forRole($this->role);
        if (in_array('leads.edit', $keys, true) && ! in_array('leads.edit_limited', $keys, true)) {
            $keys[] = 'leads.edit_limited';
        }

        return array_values(array_intersect(Permissions::keys(), $keys));
    }

    public function userSessions()
    {
        return $this->hasMany(UserSession::class);
    }

    /** Bump token_version and revoke every tracked session (optionally keeping one jti). */
    public function invalidateSessions(string $reason, ?string $exceptJti = null, bool $bump = true): void
    {
        if ($bump) {
            $this->token_version = (int) $this->token_version + 1;
        }
        $q = UserSession::where('user_id', $this->id)->where('revoked', false);
        if ($exceptJti) {
            $q->where('jti', '!=', $exceptJti);
        }
        foreach ($q->get() as $s) {
            $s->revoke($reason);
        }
    }
}

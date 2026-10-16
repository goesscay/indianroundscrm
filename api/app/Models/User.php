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

    public const ROLES = ['super_admin', 'admin', 'sales', 'operations']; // system role slugs; custom roles live in the `roles` table
    public const STATUSES = ['active', 'inactive', 'suspended', 'locked', 'password_reset_required'];

    protected $fillable = [
        'name', 'email', 'password', 'role', 'staff_code', 'consultant_code', 'destinations', 'mobile', 'active',
        'account_status', 'token_version', 'failed_login_attempts', 'locked_until',
        'last_login_at', 'last_logout_at', 'password_changed_at',
    ];

    protected $hidden = ['password', 'remember_token', 'token_version'];

    protected $appends = ['role_name', 'default_destination'];

    /** The primary destination: the first of their destinations, used wherever one value is pre-filled. */
    public function getDefaultDestinationAttribute(): ?string
    {
        $d = $this->destinations;

        return is_array($d) && isset($d[0]) && $d[0] !== '' ? (string) $d[0] : null;
    }

    public function getRoleNameAttribute(): string
    {
        return Permissions::roleName($this->role);
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'destinations' => 'array',
            'token_version' => 'integer',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'last_logout_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /** Admin and Super Admin both have every permission. */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    /** The top-most role: the only one that may create, change or remove Super Admins. */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function hasRole(string ...$roles): bool
    {
        $roles = array_map(fn ($r) => $r === 'ops' ? 'operations' : $r, $roles);
        if ($this->role === 'super_admin' && in_array('admin', $roles, true)) {
            return true;                                          // a Super Admin is an Admin, and more
        }

        return in_array($this->role, $roles, true);
    }

    /** Admin bypasses every check; other roles are resolved from role_permissions. Unknown keys are denied. */
    public function hasPermission(string $key): bool
    {
        if (! Permissions::isValid($key)) {
            return false;
        }
        if ($this->isAdmin()) {
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

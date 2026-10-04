<?php

namespace App\Models;

use App\Models\Concerns\StringId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class UserSession extends Model
{
    use StringId;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['session_id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'string',
            'revoked' => 'boolean',
            'created_at' => 'datetime',
            'last_active_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function revoke(string $reason): void
    {
        $this->forceFill(['revoked' => true, 'revoked_at' => now(), 'revoke_reason' => $reason])->save();
        // Drop the underlying Laravel session row immediately (database session driver).
        if ($this->session_id && config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('id', $this->session_id)->delete();
        }
    }

    public static function deviceFromUserAgent(?string $ua): string
    {
        if (! $ua) {
            return 'Unknown device';
        }
        $ua = strtolower($ua);
        $plat = 'Unknown';
        if (str_contains($ua, 'windows')) {
            $plat = 'Windows';
        } elseif (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios')) {
            $plat = 'iOS';
        } elseif (str_contains($ua, 'android')) {
            $plat = 'Android';
        } elseif (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh')) {
            $plat = 'macOS';
        } elseif (str_contains($ua, 'linux')) {
            $plat = 'Linux';
        }
        $browser = '';
        if (str_contains($ua, 'edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'safari')) {
            $browser = 'Safari';
        }

        return $browser ? "$plat · $browser" : $plat;
    }
}

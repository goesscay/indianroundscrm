<?php

namespace App\Models;

use App\Crm\ApiModel;
use App\Support\Numbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use ApiModel;

    public const TYPES = ['individual', 'family', 'corporate', 'agent'];
    public const STATUSES = ['active', 'inactive'];

    protected $table = 'customers';
    protected $guarded = ['id'];
    protected $stringIds = ['owner_id', 'created_by'];
    protected $hidden = ['phone_norm', 'email_norm'];

    protected static function booted(): void
    {
        static::saving(function (Customer $c) {
            $c->phone_norm = Numbers::phone($c->phone);
            $c->email_norm = $c->email ? mb_strtolower(trim($c->email)) : null;
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)->orderBy('position');
    }

    public function travellers(): HasMany
    {
        return $this->hasMany(CustomerTraveller::class)->orderBy('position');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class)->orderByDesc('id');
    }

    /** Brief shape embedded in other records. */
    public function brief(): array
    {
        return ['id' => (string) $this->id, 'customer_no' => $this->customer_no, 'name' => $this->name];
    }

    /** List/detail shape without children. Aggregates are read from withCount/withSum attributes when present. */
    public function toApi(): array
    {
        $a = $this->toArray();
        unset($a['contacts'], $a['travellers'], $a['owner']);
        $a['owner'] = $this->owner ? ['id' => (string) $this->owner->id, 'name' => $this->owner->name] : null;
        $a['deals_count'] = (int) ($this->deals_count ?? 0);
        $a['open_pipeline'] = (float) ($this->open_pipeline ?? 0);
        $a['won_value'] = (float) ($this->won_value ?? 0);

        return $a;
    }
}

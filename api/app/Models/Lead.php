<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use ApiModel;

    protected $table = 'leads';
    protected $guarded = ['id'];
    protected $stringIds = ['staff_id', 'customer_id', 'deal_id'];
    protected $hidden = ['stayCities'];

    protected $casts = [
        'duration_days' => 'integer',
        'adults' => 'integer',
        'children' => 'integer',
        'rooms' => 'integer',
        'passport_available' => 'boolean',
        'quote_received' => 'boolean',
        'quote_sent_to_customer' => 'boolean',
        'supplier_quote_amount' => 'float',
        'selling_amount' => 'float',
        'booking_amount_received' => 'float',
        'supplier_mail_date' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function stayCities(): HasMany
    {
        return $this->hasMany(LeadStayCity::class, 'lead_id', 'lead_id')->orderBy('position');
    }

    /** list of ['city'=>..., 'nights'=>...] */
    public function stayCitiesArray(): array
    {
        return $this->stayCities->map(fn ($c) => ['city' => $c->city, 'nights' => $c->nights])->all();
    }

    public function replaceStayCities(array $cities): void
    {
        $this->stayCities()->delete();
        $rows = [];
        foreach (array_values($cities) as $i => $c) {
            $rows[] = [
                'lead_id' => $this->lead_id, 'position' => $i, 'city' => (string) ($c['city'] ?? ''),
                'nights' => isset($c['nights']) && $c['nights'] !== '' ? (int) $c['nights'] : null,
            ];
        }
        if ($rows) {
            LeadStayCity::insert($rows);
        }
        $this->unsetRelation('stayCities');
    }

    /** API representation incl. derived fields (profit, quote_pending_days, stay_cities). */
    public function toApi(): array
    {
        $a = $this->toArray();
        $a['stay_cities'] = $this->stayCitiesArray();
        $supp = (float) ($this->supplier_quote_amount ?? 0);
        $sell = (float) ($this->selling_amount ?? 0);
        $a['profit'] = ($supp || $sell) ? $sell - $supp : 0;
        // Quote & Pricing (supplier quote, selling price, profit) is for Admin, Operations and anyone with full lead editing; sales never receive these figures
        $viewer = auth()->user();
        if ($viewer && ! $viewer->hasAnyPermission('leads.edit', 'suppliers.view', 'rsq.manage')) {
            $a['supplier_quote_amount'] = $a['selling_amount'] = $a['profit'] = null;
        }
        $a['quote_pending_days'] = $this->supplier_mail_date
            ? (int) floor((now()->getTimestamp() - $this->supplier_mail_date->getTimestamp()) / 86400)
            : 0;
        return $a;
    }
}

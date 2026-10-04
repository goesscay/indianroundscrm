<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;

class RsqRequest extends Model
{
    use ApiModel;

    protected $table = 'rsq_requests';
    protected $guarded = ['id'];
    protected $casts = [
        'requested_at' => 'datetime', 'sent_at' => 'datetime',
        'first_reply_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public const LANES = ['new', 'pending_reply', 'response_received', 'completed'];

    /** Idempotent update of the row for a lead (port of _upsert_rsq_request). */
    public static function upsertFor(string $leadId, array $patch): self
    {
        $row = static::firstOrNew(['lead_id' => $leadId]);
        $row->fill($patch);
        $row->save();
        return $row;
    }

    /** Attach lead + supplier counts for dashboard cards. */
    public function toEnriched(): array
    {
        $a = $this->toArray();
        $lead = Lead::where('lead_id', $this->lead_id)->first();
        if ($lead) {
            $a['customer_name'] = $lead->customer_name;
            $a['destination'] = $lead->destination;
            $a['staff_name'] = $lead->staff_name;
            $a['travel_date'] = $lead->travel_date;
            $a['lead_status'] = $lead->lead_status;
        }
        $trackers = LeadSupplier::where('lead_id', $this->lead_id)->get(['reply_received']);
        $a['supplier_count'] = $trackers->count();
        $a['reply_count'] = $trackers->where('reply_received', true)->count();
        return $a;
    }
}

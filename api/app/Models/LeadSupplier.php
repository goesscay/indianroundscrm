<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;

class LeadSupplier extends Model
{
    use ApiModel;

    protected $table = 'lead_suppliers';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $stringIds = ['supplier_id'];
    protected $casts = [
        'reply_received' => 'boolean', 'quote_received' => 'boolean',
        'flight_included' => 'boolean', 'transfer_included' => 'boolean',
        'quote_amount' => 'float', 'sequence' => 'integer', 'followups' => 'integer', 'emails_sent' => 'integer',
        'first_email_at' => 'datetime', 'last_email_at' => 'datetime', 'reply_date' => 'datetime',
        'last_followup_at' => 'datetime', 'portal_invited_at' => 'datetime', 'last_update' => 'datetime', 'created_at' => 'datetime',
    ];
}

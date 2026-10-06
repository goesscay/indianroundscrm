<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteEmail extends Model
{
    protected $table = 'quote_emails';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['created_at' => 'datetime', 'with_pdf' => 'boolean'];
}

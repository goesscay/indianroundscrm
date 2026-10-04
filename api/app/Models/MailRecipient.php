<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailRecipient extends Model
{
    protected $table = 'mail_history_recipients';
    public $timestamps = false;
    protected $guarded = ['id'];
}

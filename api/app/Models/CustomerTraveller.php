<?php

namespace App\Models;

use App\Crm\ApiModel;
use Illuminate\Database\Eloquent\Model;

class CustomerTraveller extends Model
{
    use ApiModel;

    protected $table = 'customer_travellers';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $stringIds = ['customer_id'];
    protected $casts = [
        'passport_no' => 'encrypted',
        'dob' => 'date:Y-m-d',
        'passport_expiry' => 'date:Y-m-d',
    ];

    /** API shape; the passport number is masked unless the viewer may see it. */
    public function toApi(bool $showPassport): array
    {
        $a = $this->toArray();
        unset($a['customer_id']);
        $no = (string) ($this->passport_no ?? '');
        $a['passport_no'] = $showPassport || $no === '' ? ($no === '' ? null : $no) : str_repeat('•', max(0, strlen($no) - 3)).substr($no, -3);
        $a['passport_masked'] = ! $showPassport && $no !== '';

        return $a;
    }
}

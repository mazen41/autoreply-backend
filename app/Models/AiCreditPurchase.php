<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCreditPurchase extends Model
{
    protected $guarded = [];
    protected $casts = ['gateway_response' => 'array', 'fulfilled_at' => 'datetime'];
    public function business() { return $this->belongsTo(BusinessProfile::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function creditPackage() { return $this->belongsTo(AiCreditPackage::class, 'credit_package_id'); }
}

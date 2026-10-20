<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerIdentity extends Model
{
    protected $fillable = [
        'business_profile_id', 'customer_id', 'channel_id', 'platform', 'external_id', 'external_id_hash',
    ];

    protected $hidden = ['external_id_hash'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}

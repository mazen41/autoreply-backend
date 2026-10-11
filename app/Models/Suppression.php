<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Suppression extends Model
{
    protected $fillable = [
        'business_id', 'channel', 'contact_identifier', 'reason', 'source', 'suppressed_at',
    ];

    protected $casts = ['suppressed_at' => 'datetime'];
}

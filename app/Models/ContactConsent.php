<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactConsent extends Model
{
    protected $fillable = [
        'business_id', 'channel', 'contact_identifier', 'scope', 'status', 'source',
        'captured_at', 'evidence', 'ip_hash',
    ];

    protected $casts = ['captured_at' => 'datetime'];
}

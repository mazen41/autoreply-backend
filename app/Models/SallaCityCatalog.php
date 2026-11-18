<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SallaCityCatalog extends Model
{
    protected $fillable = [
        'country_code', 'country_id', 'status', 'generation', 'pages_completed',
        'total_pages', 'expected_city_count', 'staged_city_count', 'saved_city_count',
        'started_at', 'synced_at', 'source_channel_id', 'id_set_sha256',
        'cross_store_verified_at', 'verification_channel_id',
        'verification_id_set_sha256', 'verification_matches',
        'last_error_code', 'last_error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'synced_at' => 'datetime',
        'cross_store_verified_at' => 'datetime',
        'verification_matches' => 'boolean',
    ];
}

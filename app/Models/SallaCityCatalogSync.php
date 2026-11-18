<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SallaCityCatalogSync extends Model
{
    protected $fillable = [
        'channel_id', 'country_code', 'status', 'pages_completed',
        'expected_city_count', 'saved_city_count', 'started_at',
        'last_successful_sync_at', 'last_error_code', 'last_error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
    ];
}

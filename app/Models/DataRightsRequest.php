<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataRightsRequest extends Model
{
    protected $fillable = [
        'business_id', 'customer_id', 'requested_by', 'type', 'status', 'archive_path',
        'archive_expires_at', 'receipt', 'failure_reason', 'completed_at',
    ];

    protected $casts = [
        'receipt' => 'array',
        'archive_expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}

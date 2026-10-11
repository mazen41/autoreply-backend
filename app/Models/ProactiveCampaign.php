<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProactiveCampaign extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'message',
        'scheduled_for',
        'sent_at',
        'status',
        'segment_config',
        'recipients_count',
        'delivered_count',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'segment_config' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

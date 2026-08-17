<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaign extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'subject',
        'content',
        'audience_criteria',
        'status',
        'scheduled_at',
        'sent_at',
        'total_recipients',
        'delivered_count',
        'opened_count',
        'clicked_count',
        'failed_count',
        'error_message',
    ];

    protected $casts = [
        'audience_criteria' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function recipients()
    {
        return $this->hasMany(EmailCampaignRecipient::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaignRecipient extends Model
{
    protected $fillable = [
        'email_campaign_id',
        'conversation_id',
        'tracking_token',
        'tracking_links',
        'email',
        'status',
        'error_message',
        'sent_at',
        'opened_at',
        'clicked_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'tracking_links' => 'array',
    ];

    public function campaign()
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}

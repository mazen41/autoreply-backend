<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppCloudTemplate extends Model
{
    protected $table = 'whatsapp_cloud_templates';

    protected $fillable = [
        'business_id',
        'channel_id',
        'provider_template_id',
        'name',
        'language',
        'category',
        'components',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'components' => 'array',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class);
    }
}

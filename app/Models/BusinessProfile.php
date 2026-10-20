<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfile extends Model
{
    use HasFactory;

    protected $attributes = [
        'auto_order_confirmation_enabled' => true,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'business_type',
        'business_name',
        'description',
        'website',
        'phone',
        'address',
        'city',
        'country',
        'currency',
        'timezone',
        'working_days',
        'working_from',
        'working_to',
        'services',
        'faqs',
        'reply_style',
        'connected_channel',
        'ai_instructions',
        'ai_confidence_threshold',
        'ai_tone_style',
        'business_hours_enabled',
        'after_hours_message',
        'ai_provider',
        'ai_model',
        'google_access_token',
        'google_refresh_token',
        'knowledge_base',
        'ai_setup_tested_at',
        'media_processing_disclosed_at',
        'estimated_manual_reply_minutes',
        'estimated_hourly_rate',
        'estimated_hourly_rate_currency',
        'message_retention_days',
        'media_retention_days',
        'consent_retention_days',
        'consent_policy_acknowledged_at',
        'handoff_notification_email',
        'ai_setup_tested_config_hash',
        'automation_paused_at',
        'auto_order_confirmation_enabled',
        'web_chat_enabled',
        'web_chat_allowed_domains',
    ];

    protected $casts = [
        'working_days' => 'array',
        'faqs'         => 'array',
        'ai_tone_style' => 'array',
        'ai_setup_tested_at' => 'datetime',
        'media_processing_disclosed_at' => 'datetime',
        'estimated_manual_reply_minutes' => 'integer',
        'estimated_hourly_rate' => 'decimal:2',
        'message_retention_days' => 'integer',
        'media_retention_days' => 'integer',
        'consent_retention_days' => 'integer',
        'consent_policy_acknowledged_at' => 'datetime',
        'automation_paused_at' => 'datetime',
        'auto_order_confirmation_enabled' => 'boolean',
        'web_chat_enabled' => 'boolean',
        'web_chat_allowed_domains' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function knowledgeFiles()
    {
        return $this->hasMany(\App\Models\BusinessKnowledgeFile::class);
    }

    public function teamMembers()
    {
        return $this->hasMany(TeamMember::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'team_members', 'business_id', 'user_id');
    }

    public function businessHours()
    {
        return $this->hasMany(BusinessHour::class, 'business_id');
    }

    public function autoMessages()
    {
        return $this->hasMany(AutoMessage::class, 'business_id');
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class, 'business_id');
    }

    public function sequences()
    {
        return $this->hasMany(Sequence::class, 'business_id');
    }

    public function webChatSessions()
    {
        return $this->hasMany(WebChatSession::class, 'business_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'business_id');
    }

    public function webhooks()
    {
        return $this->hasMany(Webhook::class, 'business_id');
    }

    public function calendarEvents()
    {
        return $this->hasMany(CalendarEvent::class, 'business_id');
    }

    public function csatRatings()
    {
        return $this->hasMany(CsatRating::class, 'business_id');
    }

    public function analyticsDaily()
    {
        return $this->hasMany(AnalyticsDaily::class, 'business_id');
    }

    public function aiMetrics()
    {
        return $this->hasMany(AiMetric::class, 'business_id');
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class, 'business_id');
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class, 'business_id');
    }

    public function channels()
    {
        return $this->hasMany(Channel::class, 'business_id');
    }

    public function workflows()
    {
        return $this->hasMany(AutomationWorkflow::class, 'business_id');
    }

    public function bots()
    {
        return $this->hasMany(Bot::class, 'business_profile_id');
    }
}

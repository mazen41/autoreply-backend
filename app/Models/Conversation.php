<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'channel_id',
        'business_id',
        'bot_id',
        'is_blocked',
        'customer_id',
        'sender_id',
        'sender_name',
        'sender_email',
        'subject',
        'status',
        'ai_enabled',
        'requires_human',
        'escalated_at',
        'escalation_reason',
        'human_handling_started_at',
        'human_handled_count',
        'escalation_notified',
        'last_message_at',
        'assigned_agent_id',
        'assigned_at',
        'checkout_state',
        'ecommerce_connection_id',
        'commerce_context_status',
        'commerce_context_source',
        'commerce_context_resolved_at',
    ];

    protected $casts = [
        'last_message_at'      => 'datetime',
        'escalated_at'         => 'datetime',
        'human_handling_started_at' => 'datetime',
        'human_handled_count' => 'integer',
        'assigned_at'          => 'datetime',
        'ai_enabled'           => 'boolean',
        'is_blocked'            => 'boolean',
        'requires_human'       => 'boolean',
        'escalation_notified'  => 'boolean',
        'checkout_state'       => 'array',
        'commerce_context_resolved_at' => 'datetime',
    ];

    /**
     * Store checkout_state as valid UTF-8 JSON. A single malformed byte in a
     * customer message used to make json_encode fail and roll back the whole
     * checkout save, so orders could never be confirmed. Invalid bytes are
     * replaced; valid Arabic text is kept as-is.
     */
    public function setCheckoutStateAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['checkout_state'] = null;
            return;
        }

        $clean = self::scrubUtf8($value);
        $this->attributes['checkout_state'] = json_encode(
            $clean,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: null;
    }

    private static function scrubUtf8(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_scrub($value, 'UTF-8');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::scrubUtf8($item);
            }
        }
        return $value;
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function ecommerceConnection()
    {
        return $this->belongsTo(Channel::class, 'ecommerce_connection_id');
    }

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function bot()
    {
        return $this->belongsTo(Bot::class, 'bot_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function tags()
    {
        return $this->hasMany(ConversationTag::class);
    }

    public function sequenceEnrollments()
    {
        return $this->hasMany(SequenceEnrollment::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function latestChatMessage()
    {
        return $this->hasOne(Message::class)
            ->where(function ($query): void {
                $query->whereNull('source')->orWhereNotIn('source', [
                    'social_comment',
                    'comment_public_reply',
                    'comment_private_reply',
                ]);
            })
            ->latestOfMany();
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function handoffTask()
    {
        return $this->hasOne(HandoffTask::class);
    }

    public function commerceOrderAttempts()
    {
        return $this->hasMany(CommerceOrderAttempt::class);
    }

    public function latestCommerceOrderAttempt()
    {
        return $this->hasOne(CommerceOrderAttempt::class)->latestOfMany('created_at');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportCase extends Model
{
    protected $fillable = [
        'business_id', 'conversation_id', 'commerce_order_id', 'created_by', 'assigned_to',
        'case_number', 'subject', 'description', 'status', 'priority', 'due_at', 'resolved_at',
    ];

    protected $casts = ['due_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function business(): BelongsTo { return $this->belongsTo(BusinessProfile::class); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function commerceOrder(): BelongsTo { return $this->belongsTo(CommerceOrder::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function comments(): HasMany { return $this->hasMany(SupportCaseComment::class); }
    public function events(): HasMany { return $this->hasMany(SupportCaseEvent::class); }
}

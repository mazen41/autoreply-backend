<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMergeRecord extends Model
{
    protected $fillable = [
        'business_profile_id', 'source_customer_id', 'target_customer_id', 'performed_by',
        'target_before', 'target_after', 'conversation_ids', 'note_ids', 'identity_ids', 'reversed_at',
    ];

    protected $casts = [
        'target_before' => 'array',
        'target_after' => 'array',
        'conversation_ids' => 'array',
        'note_ids' => 'array',
        'identity_ids' => 'array',
        'reversed_at' => 'datetime',
    ];

    public function source(): BelongsTo { return $this->belongsTo(Customer::class, 'source_customer_id'); }
    public function target(): BelongsTo { return $this->belongsTo(Customer::class, 'target_customer_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'performed_by'); }
}

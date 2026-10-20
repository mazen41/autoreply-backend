<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportCaseEvent extends Model
{
    protected $fillable = ['support_case_id', 'actor_id', 'event_type', 'details'];
    protected $casts = ['details' => 'array'];

    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}

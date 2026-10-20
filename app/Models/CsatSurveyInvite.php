<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatSurveyInvite extends Model
{
    protected $fillable = ['business_id', 'conversation_id', 'token_hash', 'contact_hash', 'expires_at', 'responded_at', 'opted_out_at'];
    protected $casts = ['expires_at' => 'datetime', 'responded_at' => 'datetime', 'opted_out_at' => 'datetime'];

    public function business(): BelongsTo { return $this->belongsTo(BusinessProfile::class); }
}

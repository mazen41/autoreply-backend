<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $guarded = [];
    protected $hidden = ['response_payload'];
    protected $casts = ['metadata' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cached_tokens' => 'integer', 'credits_consumed' => 'integer'];
    public function business() { return $this->belongsTo(BusinessProfile::class); }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelPrice extends Model
{
    protected $guarded = [];
    protected $casts = ['capabilities' => 'array', 'is_active' => 'boolean', 'effective_at' => 'datetime'];
}

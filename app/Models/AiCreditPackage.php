<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCreditPackage extends Model
{
    protected $guarded = [];
    protected $casts = ['credits' => 'integer', 'price_sar' => 'decimal:2', 'is_active' => 'boolean'];
}

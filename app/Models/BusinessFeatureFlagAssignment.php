<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessFeatureFlagAssignment extends Model
{
    protected $fillable = ['business_id', 'feature_flag_id', 'enabled', 'changed_by', 'enabled_at'];

    protected $casts = ['enabled' => 'boolean', 'enabled_at' => 'datetime'];
}

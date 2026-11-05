<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiBusinessSetting extends Model
{
    protected $guarded = [];
    protected $casts = ['notification_thresholds' => 'array'];
}

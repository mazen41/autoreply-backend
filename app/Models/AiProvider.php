<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['credentials'];
    protected $casts = ['credentials' => 'encrypted:array', 'config' => 'array', 'is_active' => 'boolean'];
}

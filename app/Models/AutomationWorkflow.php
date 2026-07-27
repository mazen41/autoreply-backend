<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationWorkflow extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'active',
        'trigger_config',
        'actions_config',
        'executions_count',
    ];

    protected $casts = [
        'active' => 'boolean',
        'trigger_config' => 'array',
        'actions_config' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
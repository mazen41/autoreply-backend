<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemHealthLog extends Model
{
    use HasFactory;

    protected $table = 'system_health_logs';

    protected $fillable = [
        'component',
        'status',
        'message',
        'details',
        'is_resolved',
        'resolved_at',
    ];

    protected $casts = [
        'details' => 'array',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeComponent($query, string $component)
    {
        return $query->where('component', $component);
    }

    public function scopeCritical($query)
    {
        return $query->where('status', 'critical');
    }

    public function scopeWarning($query)
    {
        return $query->where('status', 'warning');
    }

    public function markResolved(): void
    {
        $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
        ]);
    }
}

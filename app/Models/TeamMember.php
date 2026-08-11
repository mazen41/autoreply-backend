<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    protected $fillable = [
        'business_id',
        'user_id',
        'role',
        'is_active',
        'invited_at',
        'joined_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'invited_at' => 'datetime',
        'joined_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }

    public function scopeOwners($query)
    {
        return $query->where('role', 'owner');
    }

    public function scopeAgents($query)
    {
        return $query->where('role', 'agent');
    }

    public function scopeViewers($query)
    {
        return $query->where('role', 'viewer');
    }

    public function hasPermission($permission): bool
    {
        $permissions = [
            'owner' => ['read', 'write', 'delete', 'manage_team', 'manage_billing'],
            'agent' => ['read', 'write'],
            'viewer' => ['read'],
        ];

        return in_array($permission, $permissions[$this->role] ?? []);
    }
}

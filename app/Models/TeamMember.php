<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    use HasFactory;
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

    /** Single source of truth for role abilities. */
    public const ROLE_PERMISSIONS = [
        'owner' => ['read', 'write', 'delete', 'manage_team', 'manage_billing', 'conversation_read', 'conversation_write', 'assigned_work', 'automation_read', 'automation_write'],
        'manager' => ['read', 'write', 'delete', 'conversation_read', 'conversation_write', 'assigned_work', 'automation_read', 'automation_write'],
        // Agents handle conversations and assigned work; no business admin, no campaign/generic write.
        'agent' => ['conversation_read', 'conversation_write', 'assigned_work', 'automation_read'],
        // Viewers are read-only.
        'viewer' => ['read', 'conversation_read', 'automation_read'],
    ];

    public static function permissionsFor(?string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    public function hasPermission($permission): bool
    {
        return in_array($permission, self::permissionsFor($this->role), true);
    }
}

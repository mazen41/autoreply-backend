<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = [
        'name',
        'name_ar',
        'description',
        'description_ar',
        'price_monthly',
        'price_yearly',
        'ai_replies_limit',
        'ai_credits_monthly',
        'channels_limit',
        'max_team_members',
        'outbound_messages_limit',
        'tools_limit',
        'blog_posts_limit',
        'features',
        'features_ar',
        'is_popular',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'features_ar' => 'array',
        'is_popular' => 'boolean',
        'is_active' => 'boolean',
        'ai_credits_monthly' => 'integer',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePopular($query)
    {
        return $query->where('is_popular', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}

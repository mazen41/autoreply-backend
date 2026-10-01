<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_globally_enabled',
        'allowed_business_ids',
    ];

    protected $casts = [
        'is_globally_enabled' => 'boolean',
        'allowed_business_ids' => 'array',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('is_globally_enabled', true);
    }

    public function scopeForKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    /**
     * Check if a feature is enabled for a specific business.
     */
    public function isEnabledForBusiness(?int $businessId): bool
    {
        if ($this->is_globally_enabled) {
            return true;
        }

        if ($businessId && !empty($this->allowed_business_ids)) {
            return in_array($businessId, $this->allowed_business_ids);
        }

        return false;
    }

    /**
     * Check if a feature flag key is enabled for a business.
     */
    public static function isEnabled(string $key, ?int $businessId = null): bool
    {
        $flag = self::forKey($key)->first();

        if (!$flag) {
            return false;
        }

        return $flag->isEnabledForBusiness($businessId);
    }
}

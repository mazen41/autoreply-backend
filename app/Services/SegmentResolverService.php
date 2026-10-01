<?php

namespace App\Services;

use App\Models\Segment;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

class SegmentResolverService
{
    /**
     * Get all customers matching a segment's rules.
     *
     * @param Segment $segment
     * @return Collection
     */
    public static function getMatchingCustomers(Segment $segment): Collection
    {
        $rules = $segment->rules ?? [];
        $query = Customer::where('business_profile_id', $segment->business_profile_id);

        // Filter by tags
        if (!empty($rules['tags']) && is_array($rules['tags'])) {
            foreach ($rules['tags'] as $tag) {
                $query->whereJsonContains('tags', $tag);
            }
        }

        // Filter by minimum spent
        if (!empty($rules['min_spent']) && is_numeric($rules['min_spent'])) {
            $query->whereHas('conversations', function ($q) use ($rules) {
                $q->where('checkout_state->product_price', '>=', (float) $rules['min_spent']);
            });
        }

        // Filter by last order days ago
        if (!empty($rules['last_order_days_ago']) && is_numeric($rules['last_order_days_ago'])) {
            $daysAgo = (int) $rules['last_order_days_ago'];
            $query->whereHas('conversations', function ($q) use ($daysAgo) {
                $q->where('created_at', '>=', now()->subDays($daysAgo));
            });
        }

        // Filter by order frequency
        if (!empty($rules['min_orders']) && is_numeric($rules['min_orders'])) {
            $query->whereHas('conversations', function ($q) {
                $q->where('checkout_state->status', 'completed');
            }, '>=', (int) $rules['min_orders']);
        }

        // Filter by location
        if (!empty($rules['location'])) {
            $query->where('custom_fields->location', $rules['location']);
        }

        return $query->get();
    }

    /**
     * Get segment statistics.
     */
    public static function getSegmentStats(Segment $segment): array
    {
        $customers = self::getMatchingCustomers($segment);

        return [
            'segment_id' => $segment->id,
            'segment_name' => $segment->name,
            'total_customers' => $customers->count(),
            'avg_lead_score' => $customers->avg('lead_score') ?? 0,
            'high_value_customers' => $customers->where('lead_score', '>=', 70)->count(),
        ];
    }
}

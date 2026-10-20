<?php

namespace App\Services;

use App\Models\Segment;
use App\Models\Customer;
use App\Models\CommerceOrder;
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

        foreach (['lifecycle_stage', 'language', 'source'] as $field) {
            if (isset($rules[$field]) && is_string($rules[$field]) && trim($rules[$field]) !== '') {
                $query->where("custom_fields->{$field}", trim($rules[$field]));
            }
        }
        if (array_key_exists('has_email', $rules) && $rules['has_email'] === true) {
            $query->whereNotNull('email')->where('email', '!=', '');
        }
        if (array_key_exists('has_phone', $rules) && $rules['has_phone'] === true) {
            $query->whereNotNull('phone')->where('phone', '!=', '');
        }
        if (isset($rules['min_lead_score']) && is_numeric($rules['min_lead_score'])) {
            $query->where('lead_score', '>=', (int) $rules['min_lead_score']);
        }
        if (!empty($rules['channel_id']) && is_numeric($rules['channel_id'])) {
            $channelId = (int) $rules['channel_id'];
            $query->where(function ($scope) use ($channelId) {
                $scope->whereHas('conversations', fn ($conversation) => $conversation->where('channel_id', $channelId))
                    ->orWhereHas('identities', fn ($identity) => $identity->where('channel_id', $channelId));
            });
        }

        // Purchase and spend rules only use synchronized CommerceOrder rows.
        // Checkout state is an unfinished draft and must never count as revenue.
        $hasOrderRules = (isset($rules['min_spent']) && is_numeric($rules['min_spent']))
            || (isset($rules['last_order_days_ago']) && is_numeric($rules['last_order_days_ago']))
            || (isset($rules['min_orders']) && is_numeric($rules['min_orders']));
        if ($hasOrderRules) {
            $buyers = self::customerIdsWithOrderCriteria(
                (int) $segment->business_profile_id,
                isset($rules['min_spent']) && is_numeric($rules['min_spent']) ? (float) $rules['min_spent'] : null,
                isset($rules['min_orders']) && is_numeric($rules['min_orders']) ? (int) $rules['min_orders'] : null,
                isset($rules['last_order_days_ago']) && is_numeric($rules['last_order_days_ago']) ? (int) $rules['last_order_days_ago'] : null,
            );
            $query->whereIntegerInRaw('customers.id', $buyers);
        }

        // Filter by location
        if (!empty($rules['location'])) {
            $query->where('custom_fields->location', $rules['location']);
        }

        return $query->get();
    }

    /**
     * Resolve order metrics against real synchronized commerce orders. This
     * batched approach also works across SQLite/MySQL/PostgreSQL without
     * dialect-specific phone-normalization SQL.
     */
    private static function customerIdsWithOrderCriteria(
        int $businessId,
        ?float $minSpent = null,
        ?int $minOrders = null,
        ?int $lastOrderDaysAgo = null,
    ): array
    {
        $customerIdsByEmail = [];
        $customerIdsByPhone = [];
        Customer::query()->where('business_profile_id', $businessId)->get(['id', 'email', 'phone'])
            ->each(function (Customer $customer) use (&$customerIdsByEmail, &$customerIdsByPhone): void {
                $email = mb_strtolower(trim((string) $customer->email));
                if ($email !== '') $customerIdsByEmail[$email][] = (int) $customer->id;
                $phone = preg_replace('/\D+/', '', (string) $customer->phone);
                if ($phone !== '') $customerIdsByPhone[$phone][] = (int) $customer->id;
            });

        $stats = [];
        $cutoff = $lastOrderDaysAgo !== null ? now()->subDays($lastOrderDaysAgo) : null;
        CommerceOrder::query()->where('business_id', $businessId)
            ->select(['id', 'customer_email', 'customer_phone', 'total', 'ordered_at'])
            ->chunkById(1000, function ($orders) use (&$stats, $customerIdsByEmail, $customerIdsByPhone, $cutoff): void {
                foreach ($orders as $order) {
                    $email = mb_strtolower(trim((string) $order->customer_email));
                    $phone = preg_replace('/\D+/', '', (string) $order->customer_phone);
                    $customerIds = array_unique(array_merge(
                        $email !== '' ? ($customerIdsByEmail[$email] ?? []) : [],
                        $phone !== '' ? ($customerIdsByPhone[$phone] ?? []) : [],
                    ));
                    foreach ($customerIds as $customerId) {
                        $stats[$customerId] ??= ['spent' => 0.0, 'orders' => 0, 'last_order_at' => null];
                        $stats[$customerId]['spent'] += (float) $order->total;
                        $stats[$customerId]['orders']++;
                        if ($order->ordered_at && (!$stats[$customerId]['last_order_at'] || $order->ordered_at->gt($stats[$customerId]['last_order_at']))) {
                            $stats[$customerId]['last_order_at'] = $order->ordered_at;
                        }
                    }
                }
            });

        return collect($stats)->filter(function (array $stat) use ($minSpent, $minOrders, $cutoff): bool {
            if ($minSpent !== null && $stat['spent'] < $minSpent) return false;
            if ($minOrders !== null && $stat['orders'] < $minOrders) return false;
            if ($cutoff !== null && (!$stat['last_order_at'] || $stat['last_order_at']->lt($cutoff))) return false;
            return true;
        })->keys()->map(fn ($id) => (int) $id)->all();
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

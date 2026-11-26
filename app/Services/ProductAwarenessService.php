<?php

namespace App\Services;

use App\Models\Product;
use App\Models\BusinessProfile;
use Illuminate\Support\Facades\Cache;

class ProductAwarenessService
{
    /**
     * Get products for AI context
     */
    public function getProductsForContext(int $businessId, array $filters = []): array
    {
        $cacheKey = "products_context_{$businessId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, 300, function () use ($businessId, $filters) {
            $query = Product::where('business_id', $businessId)
                ->active()
                ->inStock();

            if (isset($filters['commerce_channel_id'])) {
                $query->where('commerce_channel_id', (int) $filters['commerce_channel_id']);
            }

            // Apply filters
            if (isset($filters['category'])) {
                $query->whereJsonContains('metadata', ['category' => $filters['category']]);
            }

            if (isset($filters['min_price'])) {
                $query->where('price', '>=', $filters['min_price']);
            }

            if (isset($filters['max_price'])) {
                $query->where('price', '<=', $filters['max_price']);
            }

            if (isset($filters['search'])) {
                $query->where('name', 'like', "%{$filters['search']}%");
            }

            $products = $query->limit(50)->get();

            return $products->map(function ($product) {
                return [
                    'id' => $product->commerce_external_id ?: $product->id,
                    'internal_id' => $product->id,
                    'commerce_channel_id' => $product->commerce_channel_id,
                    'commerce_external_id' => $product->commerce_external_id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => (float) $product->price,
                    'stock' => $product->stock_quantity,
                    'available' => $product->stock_quantity > 0,
                    'sku' => $product->sku,
                    'image_url' => $product->metadata['image_url'] ?? null,
                    'url' => $product->metadata['url'] ?? null,
                    'currency' => $product->metadata['currency'] ?? 'SAR',
                    'variants' => $product->metadata['variants'] ?? [],
                    'options' => $product->metadata['options'] ?? [],
                ];
            })->toArray();
        });
    }

    /**
     * Get product by ID
     */
    public function getProductById(int $businessId, int $productId): ?array
    {
        $product = Product::where('business_id', $businessId)
            ->where('id', $productId)
            ->active()
            ->first();

        if (!$product) {
            return null;
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => (float) $product->price,
            'stock' => $product->stock_quantity,
            'available' => $product->stock_quantity > 0,
            'sku' => $product->sku,
            'metadata' => $product->metadata,
        ];
    }

    /**
     * Search products by name or SKU
     */
    public function searchProducts(int $businessId, string $query): array
    {
        $products = Product::where('business_id', $businessId)
            ->active()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('sku', 'like', "%{$query}%");
            })
            ->limit(20)
            ->get();

        return $products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->price,
                'stock' => $product->stock_quantity,
                'available' => $product->stock_quantity > 0,
            ];
        })->toArray();
    }

    /**
     * Get product inventory status
     */
    public function getInventoryStatus(int $businessId): array
    {
        $products = Product::where('business_id', $businessId)
            ->active()
            ->get();

        $totalProducts = $products->count();
        $inStock = $products->where('stock_quantity', '>', 0)->count();
        $lowStock = $products->where('stock_quantity', '>', 0)->where('stock_quantity', '<', 10)->count();
        $outOfStock = $products->where('stock_quantity', 0)->count();

        return [
            'total_products' => $totalProducts,
            'in_stock' => $inStock,
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'stock_percentage' => $totalProducts > 0 ? round(($inStock / $totalProducts) * 100, 2) : 0,
        ];
    }

    /**
     * Format product information for AI response
     */
    public function formatProductForAI(array $product): string
    {
        $availability = $product['available'] ? 'متوفر' : 'غير متوفر';
        $price = number_format($product['price'], 2);
        
        return "المنتج: {$product['name']}\nالسعر: {$price}\nالحالة: {$availability}";
    }

    /**
     * Build AI context string with product information
     */
    public function buildProductContext(int $businessId): string
    {
        $products = $this->getProductsForContext($businessId, ['limit' => 10]);
        
        if (empty($products)) {
            return 'لا توجد منتجات مسجلة حالياً.';
        }

        $context = "المنتجات المتاحة:\n";
        foreach ($products as $product) {
            $context .= "- {$product['name']}: {$product['price']} جنيه ({$product['stock']} قطعة)\n";
        }

        return $context;
    }

    /**
     * Build AI context as a structured array (name, price, currency, available, url).
     * This is the shape AICapabilitiesService::getUltimateSystemPrompt() expects
     * for $context['products'] — use this instead of buildProductContext() when
     * passing products into ProcessAutoReply's AI context, since that method
     * does a foreach() over $context['products'] and expects an array, not a
     * pre-formatted string.
     */
    public function buildProductContextArray(int $businessId, ?int $commerceConnectionId = null): array
    {
        $filters = ['limit' => 10];
        if ($commerceConnectionId !== null) {
            $filters['commerce_channel_id'] = $commerceConnectionId;
        }
        $products = $this->getProductsForContext($businessId, $filters);

        return array_map(function ($product) {
            // A product with sizes or colours can cost different amounts per option.
            // Never present one base price for it: show the range, or no price, until the
            // customer chooses an option. Otherwise the model quotes a price Salla won't charge.
            $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
            $hasOptions = $variants !== [] || !empty($product['options']);
            $variantPrices = [];
            foreach ($variants as $variant) {
                if (is_array($variant) && isset($variant['price']) && is_numeric($variant['price'])) {
                    $variantPrices[] = (float) $variant['price'];
                }
            }
            $price = $product['price'] ?? null;
            $priceRange = null;
            $priceNote = null;
            if ($hasOptions) {
                $distinct = array_values(array_unique($variantPrices));
                if (count($distinct) === 1) {
                    $price = $distinct[0];
                } elseif (count($distinct) > 1) {
                    $priceRange = ['min' => min($distinct), 'max' => max($distinct)];
                    $price = null;
                    $priceNote = 'Price depends on the chosen option. Give the range, and ask the customer to choose before quoting an exact price.';
                } else {
                    $price = null;
                    $priceNote = 'Price depends on the chosen option. Ask the customer to choose before quoting an exact price.';
                }
            }

            return [
                'id'        => $product['id'] ?? null,
                'internal_id' => $product['internal_id'] ?? null,
                'commerce_channel_id' => $product['commerce_channel_id'] ?? null,
                'commerce_external_id' => $product['commerce_external_id'] ?? null,
                'name'      => $product['name'] ?? 'Unknown',
                'price'     => $price,
                'price_range' => $priceRange,
                'price_note' => $priceNote,
                'currency'  => $product['currency'] ?? 'SAR',
                'available' => $product['available'] ?? true,
                'url'       => $product['url'] ?? null,
                'image_url' => $product['image_url'] ?? null,
                'variants'  => $variants,
            ];
        }, $products);
    }
}

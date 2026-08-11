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
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => (float) $product->price,
                    'stock' => $product->stock_quantity,
                    'available' => $product->stock_quantity > 0,
                    'sku' => $product->sku,
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
}

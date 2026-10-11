<?php

namespace Tests\Unit;

use App\Services\ShopifyVariantSelection;
use PHPUnit\Framework\TestCase;

class ShopifyVariantSelectionTest extends TestCase
{
    private function variants(): array
    {
        return [
            ['id' => 'gid://shopify/ProductVariant/101', 'title' => 'Small', 'sku' => 'SM', 'price' => '10.00'],
            ['id' => 'gid://shopify/ProductVariant/102', 'title' => 'Large', 'sku' => 'LG', 'price' => '12.00'],
            ['id' => 'gid://shopify/ProductVariant/103', 'title' => 'Unavailable', 'availableForSale' => false],
        ];
    }

    public function test_matches_a_variant_by_exact_title_or_sku(): void
    {
        $this->assertSame('gid://shopify/ProductVariant/102', ShopifyVariantSelection::match($this->variants(), 'Large')['id']);
        $this->assertSame('gid://shopify/ProductVariant/101', ShopifyVariantSelection::match($this->variants(), 'SM')['id']);
    }

    public function test_matches_numbered_choice_from_available_options(): void
    {
        $this->assertSame('gid://shopify/ProductVariant/102', ShopifyVariantSelection::match($this->variants(), '2')['id']);
    }

    public function test_does_not_select_unavailable_or_ambiguous_variants(): void
    {
        $this->assertNull(ShopifyVariantSelection::match($this->variants(), '3'));
        $this->assertNull(ShopifyVariantSelection::match($this->variants(), ''));
        $this->assertNull(ShopifyVariantSelection::match([
            ['id' => 'gid://shopify/ProductVariant/201', 'title' => 'Standard'],
            ['id' => 'gid://shopify/ProductVariant/202', 'title' => 'Standard'],
        ], 'Standard'));
    }

    public function test_rejects_unknown_variant_text_instead_of_guessing(): void
    {
        $this->assertNull(ShopifyVariantSelection::match($this->variants(), 'I want something else'));
    }
}

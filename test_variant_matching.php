<?php
/**
 * Test script: Verify getVariantPriceForOptions name-fallback matching
 * against the live Salla API for the demo store jacket (product 783926907).
 *
 * Run on server: php test_variant_matching.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;

$channel = App\Models\Channel::find(89);
if (!$channel) {
    echo "ERROR: Channel 89 not found\n";
    exit(1);
}

$salla = new App\Services\SallaService();
$productId = '783926907'; // Jacket

// Clear any stale cache
Cache::forget("salla_variant_prices_channel_89_product_{$productId}");

echo "=== Test: Jacket variant price resolution ===\n\n";

// Get product options to see the value IDs we'd select
$options = $salla->getProductOptionsForChannel($channel, $productId);
if (empty($options)) {
    echo "ERROR: No options returned for product {$productId}\n";
    exit(1);
}

echo "Product options:\n";
foreach ($options as $option) {
    echo "  Option: {$option['name']} (id: {$option['id']}, purpose: {$option['purpose']})\n";
    foreach ($option['values'] as $value) {
        echo "    Value: {$value['name']} (id: {$value['id']})\n";
    }
}
echo "\n";

// Test each value
$testCases = [];
foreach ($options as $option) {
    if (!in_array($option['purpose'], ['variant', 'variants'])) continue;
    foreach ($option['values'] as $value) {
        $testCases[] = [
            'value_id' => $value['id'],
            'value_name' => $value['name'],
            'option_name' => $option['name'],
        ];
    }
}

$passed = 0;
$failed = 0;
foreach ($testCases as $test) {
    // Clear cache between tests to ensure fresh results
    Cache::forget("salla_variant_prices_channel_89_product_{$productId}");

    $result = $salla->getVariantPriceForOptions(
        $channel,
        $productId,
        [$test['value_id']],
        [$test['value_name']]
    );

    if ($result) {
        echo "PASS: {$test['value_name']} => variant_id={$result['variant_id']}, price={$result['price']} {$result['currency']}\n";
        $passed++;
    } else {
        echo "FAIL: {$test['value_name']} (value_id={$test['value_id']}) => null\n";
        $failed++;
    }
}

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);

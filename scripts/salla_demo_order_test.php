<?php
/**
 * Standalone Salla Demo Order Test Script
 *
 * SAFETY GUARDS:
 * 1. Hardcoded target: Channel ID 6 only.
 * 2. Pre-flight check: Verifies store ID === 918791227, type === 'demo', and domain before execution.
 * 3. Never prints or exposes the OAuth access token.
 * 4. Sends exactly ONE POST request to /admin/v2/orders. Zero retries.
 * 5. Does not create or modify customers, store settings, or application files.
 *
 * DO NOT RUN WITHOUT EXPLICIT USER AUTHORIZATION.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "====================================================\n";
echo "SALLA DEMO STORE ORDER CREATION TEST (CONTROLLED)\n";
echo "====================================================\n\n";

// Guard 1: Hardcoded target channel
$targetChannelId = 6;
$channel = App\Models\Channel::find($targetChannelId);

if (!$channel || $channel->type !== 'salla') {
    fwrite(STDERR, "[ABORT] Channel {$targetChannelId} not found or not a Salla channel.\n");
    exit(1);
}

$token = $channel->access_token;
if (empty($token)) {
    fwrite(STDERR, "[ABORT] Channel {$targetChannelId} access token is missing.\n");
    exit(1);
}

// Guard 2: Pre-flight identity verification immediately before request
echo "[1/3] Running pre-flight demo store identity check...\n";
$ch = curl_init('https://api.salla.dev/admin/v2/store/info');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
]);
$rawInfo = curl_exec($ch);
$infoStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($infoStatus !== 200) {
    fwrite(STDERR, "[ABORT] Store info check failed with HTTP {$infoStatus}.\n");
    exit(1);
}

$storeData = json_decode($rawInfo, true)['data'] ?? [];
$storeId = (int) ($storeData['id'] ?? 0);
$storeType = (string) ($storeData['type'] ?? '');
$storeDomain = (string) ($storeData['domain'] ?? '');

echo " - Verified Store ID: {$storeId}\n";
echo " - Verified Store Type: {$storeType}\n";
echo " - Verified Store Domain: {$storeDomain}\n";

if ($storeId !== 918791227 || $storeType !== 'demo' || $storeDomain !== 'https://demostore.salla.sa/dev-8t7hk2hkamshhrc6') {
    fwrite(STDERR, "[ABORT] Identity mismatch! Store is not the verified demo store. Aborting immediately.\n");
    exit(1);
}

echo "Pre-flight checks PASSED.\n\n";

// Guard 3: Load the exact verified payload
echo "[2/3] Loading verified test payload...\n";
$payloadPath = __DIR__ . '/../docs/salla_demo_order_payload.json';
if (!file_exists($payloadPath)) {
    fwrite(STDERR, "[ABORT] Payload file not found at {$payloadPath}.\n");
    exit(1);
}

$payloadJson = file_get_contents($payloadPath);
$payload = json_decode($payloadJson, true);
if (!is_array($payload)) {
    fwrite(STDERR, "[ABORT] Invalid JSON in payload file.\n");
    exit(1);
}

echo " - Target product ID: " . ($payload['products'][0]['identifier'] ?? 'unknown') . "\n";
echo " - Target courier ID: " . ($payload['courier_id'] ?? 'unknown') . "\n";
echo " - Ship to City: " . ($payload['ship_to']['city'] ?? 'unknown') . "\n";
echo " - Payment method: " . ($payload['payment']['method'] ?? 'unknown') . " (" . ($payload['payment']['cash_on_delivery']['amount'] ?? 0) . " SAR)\n";
echo " - Receiver notify flag: " . ($payload['receiver']['notify'] ? 'TRUE' : 'FALSE') . "\n\n";

// Guard 4: Execute exactly one POST request (NO RETRIES)
echo "[3/3] Sending single order creation request to Salla...\n";
$ch = curl_init('https://api.salla.dev/admin/v2/orders');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json',
    'Content-Type: application/json',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    fwrite(STDERR, "[ERROR] Network error during request: {$curlError}\n");
    exit(1);
}

$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
$bodyData = json_decode($body, true);

echo "\n==================== RESULT ====================\n";
echo "HTTP Status Code: {$httpCode}\n";

// Extract request-id if returned in headers
if (preg_match('/x-request-id:\s*([^\r\n]+)/i', $headers, $m)) {
    echo "Salla Request ID: " . trim($m[1]) . "\n";
}

if ($httpCode === 201 || $httpCode === 200) {
    echo "SUCCESS: Order created!\n";
    echo "Order ID: " . ($bodyData['data']['id'] ?? $bodyData['id'] ?? 'N/A') . "\n";
    echo "Order Reference: " . ($bodyData['data']['reference_id'] ?? 'N/A') . "\n";
    echo "Order Status: " . ($bodyData['data']['status']['name'] ?? 'N/A') . "\n";
    echo "Total: " . ($bodyData['data']['total']['amount'] ?? 'N/A') . " " . ($bodyData['data']['total']['currency'] ?? 'SAR') . "\n";
} else {
    echo "VALIDATION / API REJECTION (HTTP {$httpCode}):\n";
    echo (is_array($bodyData) ? json_encode($bodyData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $body) . "\n";
}
echo "================================================\n";

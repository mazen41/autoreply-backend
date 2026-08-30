<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$raw = [
    "data" => [
        ["id" => 1, "name" => "Dress", "price" => ["amount" => 174, "currency_code" => "SAR"], "quantity" => 5, "thumbnail" => "https://cdn.salla.sa/dress.jpg"],
    ],
    "pagination" => ["total" => 1]
];
$service = new App\Services\SallaService();
$productsAggregateContext = $service->formatProductsListForAI($raw);
$aiResult = ["needs_images" => true];

$images = [];
if (!empty($aiResult["needs_images"]) && !empty($productsAggregateContext["items"])) {
    $images = array_values(array_filter(array_column($productsAggregateContext["items"], "image_url")));
}
var_dump($images);


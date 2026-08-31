<?php
require __DIR__."/vendor/autoload.php";
$app = require_once __DIR__."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$c = App\Models\Conversation::first();
if ($c) {
    echo "Before: " . ($c->ai_enabled ? "true" : "false") . "\n";
    
    // Simulate what the controller does
    $c->update(["ai_enabled" => !$c->ai_enabled]);
    
    // Fetch it again
    $c2 = App\Models\Conversation::find($c->id);
    echo "After: " . ($c2->ai_enabled ? "true" : "false") . "\n";
} else {
    echo "No conversation found.\n";
}


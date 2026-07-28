<?php

// Test script to check if we can manually test HTML extraction on an existing message
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Gmail HTML extraction from database...\n\n";

// Get the most recent Gmail message
$message = \App\Models\Message::where('gmail_message_id', '!=', null)
    ->where('content_html', null)
    ->latest()
    ->first();

if (!$message) {
    echo "No Gmail message with null HTML found.\n";
    exit(1);
}

echo "Found message ID: " . $message->id . "\n";
echo "Gmail message ID: " . $message->gmail_message_id . "\n";
echo "Current content (plain text): " . substr($message->content, 0, 200) . "...\n";
echo "Current HTML: " . ($message->content_html ? "Yes" : "No") . "\n";

// Since we can't access Gmail API with expired tokens, let's check if the issue is with existing messages
// The solution is to reconnect Gmail and fetch new messages with the updated HTML extraction logic

echo "\n=== SOLUTION ===\n";
echo "1. Reconnect your Gmail account through the UI (/dashboard/channels)\n";
echo "2. The updated HTML extraction code will work for new messages\n";
echo "3. Or, we can create a migration to re-fetch existing messages\n";

// Check if there are any messages with HTML
$messagesWithHtml = \App\Models\Message::where('content_html', '!=', null)->count();
echo "\nCurrent messages with HTML in database: " . $messagesWithHtml . "\n";

// Check Gmail messages count
$gmailMessages = \App\Models\Message::where('gmail_message_id', '!=', null)->count();
echo "Total Gmail messages: " . $gmailMessages . "\n";
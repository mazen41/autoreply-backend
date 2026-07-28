<?php

// Test script to check Gmail HTML extraction
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Gmail HTML extraction...\n\n";

// Get a Gmail channel
$channel = \App\Models\Channel::where('type', 'gmail')->where('status', 'connected')->first();
if (!$channel) {
    echo "No connected Gmail channel found.\n";
    exit(1);
}

echo "Found Gmail channel: " . $channel->page_name . "\n\n";

// Get authenticated client
$controller = new \App\Http\Controllers\GmailController();
$client = $controller->getAuthenticatedClient($channel);
if (!$client) {
    echo "Failed to get authenticated Gmail client.\n";
    exit(1);
}

echo "Got authenticated client.\n\n";

// Fetch a recent message
$gmail = new \Google\Service\Gmail($client);
$results = $gmail->users_messages->listUsersMessages('me', [
    'labelIds' => ['INBOX'],
    'maxResults' => 1,
]);

$messages = $results->getMessages() ?? [];
if (empty($messages)) {
    echo "No messages found in INBOX.\n";
    exit(1);
}

$msgRef = $messages[0];
$msgId = $msgRef->getId();
echo "Fetching message: " . $msgId . "\n\n";

// Fetch full message
$full = $gmail->users_messages->get('me', $msgId, ['format' => 'full']);
$headers = collect($full->getPayload()->getHeaders())->keyBy('name');

$subject = $headers->get('Subject')?->getValue() ?? '(no subject)';
echo "Subject: " . $subject . "\n";

// Test HTML extraction
$bodyHtml = $controller->extractHtmlBody($full->getPayload());
$body = $controller->extractBody($full->getPayload());

echo "\nPlain text length: " . strlen($body) . "\n";
echo "HTML length: " . strlen($bodyHtml) . "\n";

if ($bodyHtml) {
    echo "\nFirst 500 chars of HTML:\n";
    echo substr($bodyHtml, 0, 500) . "\n";
    echo "\nHTML extraction successful!\n";
} else {
    echo "\nNo HTML found.\n";
    echo "Payload MIME type: " . $full->getPayload()->getMimeType() . "\n";
    echo "Number of parts: " . count($full->getPayload()->getParts() ?? []) . "\n";
    
    // Debug: show all parts
    if ($full->getPayload()->getParts()) {
        echo "\nParts found:\n";
        foreach ($full->getPayload()->getParts() as $i => $part) {
            echo "Part $i: " . $part->getMimeType() . "\n";
        }
    }
}
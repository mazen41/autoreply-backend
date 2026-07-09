<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$client = new Google\Client();
$client->setClientId(env('GOOGLE_CLIENT_ID'));
$client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
$client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
$client->addScope(Google\Service\Gmail::GMAIL_READONLY);
$client->addScope(Google\Service\Gmail::GMAIL_SEND);
$client->setAccessType('offline');
$client->setPrompt('consent');
$client->setState('1');
echo $client->createAuthUrl() . "\n";
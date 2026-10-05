<?php
$content = file_get_contents("D:/autoreply/autoreply-backend/app/Jobs/ProcessAutoReply.php");
$lines = explode("\n", $content);
foreach ($lines as $k => $line) {
    if (strpos($line, "isCheckoutMapsLink =") !== false) echo ($k+1) . ": " . trim($line) . "\n";
    if (strpos($line, "ProcessAutoReply: AI decision") !== false) echo ($k+1) . ": " . trim($line) . "\n";
    if (strpos($line, "ProcessAutoReply: AI confidence below threshold") !== false) echo ($k+1) . ": " . trim($line) . "\n";
}


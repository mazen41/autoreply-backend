<?php
$aiResult = ["needs_images" => true];
$imagesActuallySent = 0;
if (!empty($aiResult["needs_images"]) && $imagesActuallySent === 0) {
    echo "OVERRIDE TRIGGERED!";
} else {
    echo "OVERRIDE FAILED!";
}


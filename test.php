<?php
$msg = "https://maps.app.goo.gl/qbFpmrspZT1vqBCi7?g_st=ai";
$regex = "~https?://(?:maps\.app\.goo\.gl|goo\.gl/maps|(?:www\.)?google\.com/maps|maps\.google\.com/maps)[^\s<>\]\)]*~i";
var_dump((bool) preg_match($regex, $msg));


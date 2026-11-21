<?php

$nlp = app(\App\Services\Voc\QueryAttributeExtractor::class)->extract('gatas');
echo json_encode([
    'product' => $nlp['product'],
    'exclusions' => $nlp['exclusions'],
    'keywords' => $nlp['keywords'],
    'dietary' => $nlp['dietary'],
], JSON_PRETTY_PRINT);

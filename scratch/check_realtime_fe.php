<?php

$dir = new RecursiveDirectoryIterator(__DIR__.'/../../GraduationManager_FE-BichTram/DATN_Frontend/frontend');
$iterator = new RecursiveIteratorIterator($dir);
$regex = new RegexIterator($iterator, '/\.(ts|tsx|js|jsx)$/', RecursiveRegexIterator::GET_MATCH);

foreach ($regex as $file => $value) {
    $content = file_get_contents($file);
    if (str_contains($content, 'EventSource') || str_contains($content, 'realtime/stream')) {
        echo "Found in: $file\n";
    }
}

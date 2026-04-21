<?php

declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$resolvedPath = is_string($path) ? __DIR__ . $path : __DIR__;

if ($path !== null && $path !== '/' && is_file($resolvedPath)) {
    return false;
}

require __DIR__ . '/index.php';

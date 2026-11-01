<?php

// Router for the local preview command; public/ remains the document root.
$publicRoot = realpath(__DIR__.'/public');
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$requestedFile = realpath($publicRoot.DIRECTORY_SEPARATOR.ltrim($requestPath, '/'));

if ($requestPath !== '/' && $requestedFile && str_starts_with($requestedFile, $publicRoot.DIRECTORY_SEPARATOR) && is_file($requestedFile)) {
    return false;
}

require $publicRoot.'/index.php';

<?php
// Development-only router: deny database files, private folders and hidden files.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (str_contains($path, "\0") || preg_match('~(?:^|/)(?:data|\.git|\.env|vendor)(?:/|$)|(?:^|/)\.|\.(?:(?:sqlite|db)(?:-wal|-shm|-journal)?|sql|bak|backup|log|txt|md|php)$~i', $path)) {
    if ($path !== '/' && $path !== '/index.php') { http_response_code(404); exit('Not found'); }
}
$file = realpath(__DIR__ . $path);
if ($file && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR) && is_file($file)) {
    header('Cache-Control: public, max-age=86400');
    return false;
}
require __DIR__ . '/index.php';

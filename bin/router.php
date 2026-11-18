<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in server: `php -S 0.0.0.0:8080 -t www bin/router.php`.
 *
 * Without one, the built-in server answers a path that looks like a file — a dot in its
 * last segment — and is not one with its own 404 before PHP ever runs, so
 * /<slug>/precache.json would never reach the app. nginx (try_files) and Apache
 * (www/.htaccess) already hand every non-file to the front controller; this does the
 * same. Development and the browser tests only: it lives outside www/, so no server that
 * serves the docroot can ever expose it.
 *
 * A versioned script, /js/<8 hex>/<path>, is the file www/js/<path> (bin/script-path.php,
 * as nginx and Apache rewrite it). The built-in server cannot be told to serve another
 * file than the one asked for, so this sends it itself.
 */
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

require_once __DIR__ . '/script-path.php';
$script = eventapp_script_file($path, dirname(__DIR__) . '/www');
if ($script !== null) {
    header('Content-Type: text/javascript; charset=utf-8');
    readfile($script);

    return true;
}

if ($path !== '/' && !str_contains($path, '..') && is_file(__DIR__ . '/../www' . $path)) {
    return false;
}

require __DIR__ . '/../www/index.php';

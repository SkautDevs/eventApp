<?php

declare(strict_types=1);

/*
 * The browser tests' router for PHP's built-in server: bin/router.php, plus the JavaScript
 * unit tests. A path under /__tests__/ is a file of tests/js (runner.html, all.js, the
 * harness and the *.test.js files), served with its type and never cached; everything
 * else is the app, exactly as bin/router.php serves it. The test files import the code
 * under test by its unversioned URL, /js/<path>, which is the plain file www/js/<path>.
 * Test only: no server outside BrowserTestCase ever runs this.
 */
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if (str_starts_with($path, '/__tests__/')) {
    $root = (string) realpath(__DIR__ . '/../js');
    $file = realpath($root . '/' . substr($path, strlen('/__tests__/')));
    if ($file === false || !str_starts_with($file, $root . '/') || !is_file($file)) {
        http_response_code(404);

        return true;
    }
    $types = ['html' => 'text/html', 'js' => 'text/javascript'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'text/plain') . '; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($file);

    return true;
}

return require dirname(__DIR__, 2) . '/bin/router.php';

<?php

declare(strict_types=1);

/*
 * The gate servers' router (tools/Gate/Server.php, tools/Gate/RenderGate.php): a file
 * under www/ is served as it is, anything else boots the app against the gate's events
 * directory, the one place the gate depends on the app's signature:
 * Kernel::boot(string $uri, ?string $eventsDir).
 *
 * Its file-serving decision must mirror bin/router.php's, the router of the dev server
 * and the browser tests: whatever that one serves as a file (today a file under www/;
 * once the scripts move, the virtual /js/<8 hex>/<path> URLs too) this one serves the
 * same way, or the gates dump a different app from the one the tests run.
 *
 * The render gate holds back every script this router serves by GATE_SCRIPT_DELAY_MS
 * (RenderGate::SCRIPT_DELAY_MS), so the fonts the first layout asked for are in before
 * any script measures the page; that includes the future virtual /js/<8 hex>/<path>
 * URLs. The hold-back keys on "the response is a JavaScript file being served", not on
 * the branch that found it: a branch added for versioned script paths must call
 * $holdScript with the path it serves too.
 */
$root = dirname(__DIR__, 2);
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

/** Holds back a JavaScript response by GATE_SCRIPT_DELAY_MS; unset (the HTML gate), it does nothing. */
$holdScript = static function (string $servedPath): void {
    $delay = (int) getenv('GATE_SCRIPT_DELAY_MS');
    if ($delay > 0 && str_ends_with($servedPath, '.js')) {
        usleep($delay * 1000);
    }
};

if ($path !== '/' && !str_contains($path, '..') && is_file($root . '/www' . $path)) {
    $holdScript($path);

    return false;
}

require $root . '/vendor/autoload.php';

App\Kernel::boot((string) ($_SERVER['REQUEST_URI'] ?? '/'), (string) getenv('GATE_EVENTS_DIR'))->run();

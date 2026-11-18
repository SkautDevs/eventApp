<?php

declare(strict_types=1);

/*
 * The versioned script path, for the two PHP routers (bin/router.php, the dev server's and
 * the browser tests'; tools/Gate/front.php, the gates'). A script is linked as
 * /js/<8 hex>/<path> (App\Http\ScriptVersion) and is the file www/js/<path>: the segment
 * only makes the URL change with the content. nginx (docker/nginx.conf) and Apache
 * (www/.htaccess) strip it with a rewrite of their own; the three must agree.
 */

/**
 * The absolute file for a /js/<8 hex>/<rest> path whose target is a file under
 * <docroot>/js, or null: a path without the version segment, with `..` in it, or to no
 * file is not a versioned script.
 */
function eventapp_script_file(string $path, string $docroot): ?string
{
    if (str_contains($path, '..') || preg_match('#^/js/[0-9a-f]{8}/(.+)$#', $path, $match) !== 1) {
        return null;
    }
    $file = $docroot . '/js/' . $match[1];

    return is_file($file) ? $file : null;
}

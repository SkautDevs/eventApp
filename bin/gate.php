<?php

declare(strict_types=1);

/*
 * The refactor round's safety net: dumps every page's HTTP response under a frozen clock,
 * so a reference tree and a candidate tree can be diffed.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.5-alpine sh -c \
 *     'apk add --no-cache libfaketime tzdata >/dev/null && php bin/gate.php html <label>'
 *   docker run --rm -v "$PWD":/app -w /app php:8.5-alpine php bin/gate.php diff [--frontend] <labelA> <labelB>
 *
 *   docker compose --profile browser run --rm --use-aliases test sh -c \
 *     'apk add --no-cache libfaketime tzdata >/dev/null && php bin/gate.php render <label>'
 *   docker compose --profile browser stop chrome
 *
 * `html <label>` writes var/gate/<label>/html/ (replacing it); `render <label>` writes
 * var/gate/<label>/render/ (replacing it): every screen state of tools/Gate/RenderGate.php
 * in Chrome, as a style dump (tools/Gate/dump.js) and a DOM, both gzipped. `diff` compares
 * the two labels' whole trees, html/ and render/ together, a .gz file by its content; it
 * exits 0 and prints `identical: N files` when they match after normalisation
 * (tools/Gate/Normalizer.php; a style dump is compared as it is), else prints each
 * differing file with a short unified diff and exits 1. `--frontend` also drops script and
 * stylesheet elements and the precache list's script and stylesheet assets on both sides
 * (the DOM dumps included, the style dumps never). tzdata is not optional: without it
 * libc ignores TZ and the frozen instant is read as UTC.
 *
 * Requires tests/bootstrap.php, as bin/baseline.php does: a throwaway VAPID pair and
 * PROGRAM_PROVIDER_<SLUG>=local for every event and fixture event.
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use Tools\Gate\Comparison;
use Tools\Gate\HtmlGate;
use Tools\Gate\RenderGate;

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$frontend = in_array('--frontend', $args, true);
$args = array_values(array_filter($args, static fn(string $arg): bool => $arg !== '--frontend'));
$label = static fn(?string $name): ?string => is_string($name) && preg_match('/^[A-Za-z0-9._-]+$/', $name) === 1 && !str_starts_with($name, '.') ? $name : null;

// The compose and docker runs are root: what the gate wrote belongs to whoever owns the project.
$handBack = static function (string $path) use ($root): void {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !file_exists($path)) {
        return;
    }
    $owner = (int) fileowner($root);
    $group = (int) filegroup($root);
    $items = is_dir($path)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST)
        : [];
    foreach ([new SplFileInfo($path), ...$items] as $item) {
        lchown($item->getPathname(), $owner);
        lchgrp($item->getPathname(), $group);
    }
};

switch ($args[0] ?? '') {
    case 'html':
        $name = $label($args[1] ?? null);
        if ($name === null) {
            fwrite(STDERR, "usage: php bin/gate.php html <label>\n");
            exit(2);
        }
        // no exit() inside the try: exit skips finally, and a failed run must hand back too
        try {
            $count = (new HtmlGate())->dump($root . '/var/gate/' . $name . '/html');
            $code = 0;
        } catch (Throwable $e) {
            fwrite(STDERR, 'gate: ' . $e->getMessage() . "\n");
            $code = 1;
        }
        $handBack($root . '/var/gate');
        // the server compiles templates into var/twig as the user it runs as
        $handBack($root . '/var/twig');
        if ($code === 0) {
            echo sprintf("wrote %d files to var/gate/%s/html\n", $count, $name);
        }
        exit($code);

    case 'render':
        $name = $label($args[1] ?? null);
        if ($name === null) {
            fwrite(STDERR, "usage: php bin/gate.php render <label>\n");
            exit(2);
        }
        try {
            $count = RenderGate::dump($root . '/var/gate/' . $name . '/render');
            $code = 0;
        } catch (Throwable $e) {
            fwrite(STDERR, "\ngate: " . $e->getMessage() . "\n");
            $code = 1;
        }
        $handBack($root . '/var/gate');
        $handBack($root . '/var/twig');
        if ($code === 0) {
            echo sprintf("\nwrote %d files to var/gate/%s/render\n", $count, $name);
        }
        exit($code);

    case 'diff':
        $a = $label($args[1] ?? null);
        $b = $label($args[2] ?? null);
        if ($a === null || $b === null) {
            fwrite(STDERR, "usage: php bin/gate.php diff [--frontend] <labelA> <labelB>\n");
            exit(2);
        }
        foreach ([$a, $b] as $name) {
            if (!is_dir($root . '/var/gate/' . $name)) {
                fwrite(STDERR, sprintf("no dump labelled %s under var/gate\n", $name));
                exit(2);
            }
        }
        $result = Comparison::run($root . '/var/gate/' . $a, $root . '/var/gate/' . $b, $frontend);
        if ($result->differences === 0) {
            echo sprintf("identical: %d files\n", $result->files);
            exit(0);
        }
        echo $result->report;
        echo sprintf("%d of %d files differ\n", $result->differences, $result->files);
        exit(1);

    default:
        fwrite(STDERR, "usage: php bin/gate.php html <label> | render <label> | diff [--frontend] <labelA> <labelB>\n");
        exit(2);
}

<?php

// A kissj that is slow on the list and knows no participant: SessionLockTest's upstream.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/ready') {
    echo 'ok';

    return true;
}
if (str_contains($path, '/participant/')) {
    // the contract's "unknown TIE code": 404 with an empty body
    http_response_code(404);

    return true;
}
usleep(2_000_000);
http_response_code(503);
echo 'down';

return true;

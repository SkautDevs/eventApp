<?php

// A kissj that starts a pairing at once and is slow and down on everything else:
// SessionLockTest's upstream. The start is what gives the test its session cookie.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/ready') {
    echo 'ok';

    return true;
}
if ($path === '/v3/device/pairing/start') {
    header('Content-Type: application/json');
    echo json_encode([
        'deviceCode' => str_repeat('a', 32),
        'userCode' => '483291',
        'verificationUrl' => 'http://127.0.0.1/v2/event/korbo26/participant/dashboard#devicePairing',
        'expiresAt' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
        'interval' => 5,
    ]);

    return true;
}
usleep(2_000_000);
http_response_code(503);
echo 'down';

return true;

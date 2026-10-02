<?php

declare(strict_types=1);

// Router for `php -S`, started by tests that must prove nothing is fetched: it records every request
// line in the file named by TRUNK_RECORDER_LOG and answers 404 at once, so a client never waits.
$log = getenv('TRUNK_RECORDER_LOG');

if (is_string($log) && $log !== '') {
    $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '?';
    $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '?';
    file_put_contents($log, $method . ' ' . $uri . "\n", FILE_APPEND | LOCK_EX);
}

http_response_code(404);

<?php

declare(strict_types=1);

// Router for `php -S`, standing in for an identity provider's key set endpoint in tests.
$path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);

$published = getenv('TRUNK_JWKS_FILE');

match ($path) {
    '/.well-known/jwks.json' => print is_string($published) && $published !== '' ? (string) file_get_contents($published) : '{"keys":[]}',
    '/redirect' => header('Location: /.well-known/jwks.json', true, 302),
    '/big' => print str_repeat(' ', 1_048_577),
    default => http_response_code(404),
};

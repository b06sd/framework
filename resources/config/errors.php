<?php

declare(strict_types=1);

// How errors are presented to clients. Details (class, file, trace) are only ever shown in
// development (APP_ENV=local with APP_DEBUG=1); production shows a safe message and a request id.
return [
    // auto: JSON for API clients (Accept: application/json), an HTML page for browsers, text otherwise.
    // Or force one of: json, html, text.
    'format' => 'auto',
];

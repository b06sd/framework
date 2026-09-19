<?php

declare(strict_types=1);

// Limits on incoming requests, enforced before your code runs. Your web server and PHP
// (post_max_size, upload_max_filesize) still apply on top of these.
return [
    // Largest accepted request body, in bytes (2 MB). Larger requests get 413.
    'max_body_bytes' => 2 * 1024 * 1024,
    // Most files in one multipart upload, and the largest single file (8 MB).
    'max_files' => 20,
    'max_file_bytes' => 8 * 1024 * 1024,
    // Deepest nesting JsonBody::decode() accepts.
    'max_json_depth' => 16,
    // Longest request target (path plus query) accepted, in bytes. Longer gets 414. Web servers cap
    // this at about 8 KB already; the limit keeps a hostile client from feeding the router megabytes.
    'max_uri_bytes' => 8192,
    // Reverse proxies (IPs or CIDR ranges) whose X-Forwarded-Proto/Host/Port/For headers are believed,
    // e.g. ['10.0.0.0/8']. Empty = never trust them. List only proxies YOU run: anyone else can
    // send these headers, and '0.0.0.0/0' would let every client choose its own scheme, host and IP.
    'trusted_proxies' => [],
    // Response headers that make browsers refuse whole classes of attack, applied to every response
    // (error pages included). Each value is a string, or false to leave that header out. A header a
    // handler sets itself is never replaced. Remove this block (or set 'enabled' => false) to turn
    // it off; SecurityHeadersMiddleware applies the same policy to one route group instead.
    'security_headers' => [
        'enabled' => true,
        'content_type_options' => 'nosniff',
        'frame_options' => 'DENY',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'cross_origin_opener_policy' => 'same-origin',
        'cross_origin_resource_policy' => 'same-origin',
        'permissions_policy' => 'camera=(), microphone=(), geolocation=()',
        // Only the directives that are safe for every page. A full policy (default-src, script-src...)
        // is specific to your pages: set it here when you know what they load.
        'content_security_policy' => "frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        // Strict-Transport-Security is sent only on https requests. Seconds; false = never send it.
        'hsts' => 31536000,
        'hsts_include_subdomains' => true,
        'hsts_preload' => false,
    ],
];

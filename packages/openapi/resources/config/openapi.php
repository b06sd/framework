<?php

declare(strict_types=1);

use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;

// The OpenAPI document of your routes. Describe responses with #[Returns(201, OrderView::class)] on a
// handler; request bodies and query strings come from #[Body] and #[Query] parameters.
return static fn(Runtime $runtime): array => [
    'title' => $runtime->variable('APP_NAME', 'API'),
    'version' => '1.0.0',
    'description' => '',
    // Base URLs clients call, e.g. ['https://api.example.com']. Empty means "this site".
    'servers' => [],
    // Serve GET /openapi.json and GET /docs (Swagger UI). Local development only by default: the
    // document lists every route, so turn it on elsewhere only behind your own access control.
    'serve' => $runtime->environment === Environment::Local,
    // Your own authentication middleware => the OpenAPI security scheme it stands for, e.g.
    // [App\Http\RequireApiKey::class => ['apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key']]].
    'security' => [],
    'mode' => $runtime->environment === Environment::Production ? 'compiled' : 'development',
    'build' => $runtime->basePath . '/build',
];

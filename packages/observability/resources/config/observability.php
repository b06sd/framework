<?php

declare(strict_types=1);

// Metrics are kept in this process (the health capability serves them at /metrics once
// health.metrics_token is set). Tracing writes one structured log record per span (request, job)
// with trace ids; leave it off for no tracing overhead.
return [
    'metrics' => true,
    'tracing' => false,
];

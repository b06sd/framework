<?php

declare(strict_types=1);

use Trunk\Foundation\Runtime;

// Where files are kept. STORAGE_DISK picks the default disk; inject Trunk\Storage\Storage and use
// ->disk() (the default) or ->disk('s3'). Files are private unless written with
// ['visibility' => 'public']. User uploads: see docs/storage.md (serve them as downloads, never inline).
return static fn(Runtime $runtime): array => [
    'default' => $runtime->variable('STORAGE_DISK', 'local'),
    'disks' => [
        // The application's own files, outside public/: never reachable by URL.
        'local' => ['driver' => 'local', 'root' => $runtime->basePath . '/storage/app'],
        // Amazon S3, or anything S3-compatible (MinIO, Cloudflare R2, DigitalOcean Spaces): set an
        // endpoint (and path_style for MinIO). Needs `composer require league/flysystem-aws-s3-v3`.
        // Without a key, the AWS SDK's own credentials chain is used (an IAM role, for instance).
        's3' => [
            'driver' => 's3',
            'bucket' => $runtime->variable('S3_BUCKET', ''),
            'region' => $runtime->variable('S3_REGION', 'us-east-1'),
            'key' => $runtime->variable('S3_KEY', ''),
            'secret' => $runtime->secret('S3_SECRET', ''),
            'endpoint' => $runtime->variable('S3_ENDPOINT', ''),
            'path_style' => $runtime->variable('S3_PATH_STYLE', 'false') === 'true',
            'prefix' => '',
        ],
    ],
];

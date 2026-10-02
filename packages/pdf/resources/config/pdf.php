<?php

declare(strict_types=1);

use Trunk\Foundation\Runtime;

// HTML to PDF (dompdf). Inject Trunk\Pdf\Pdf and call ->render($html). dompdf never fetches a URL
// and never runs PHP or JavaScript; it reads local files (images, stylesheets) only from `assets`.
return static fn(Runtime $runtime): array => [
    // a3, a4, a5, letter or legal (each call may choose its own).
    'paper' => $runtime->variable('PDF_PAPER', 'a4'),
    // The only directory a document may load local files from, e.g. <img src="/images/logo.png">.
    'assets' => $runtime->basePath . '/public',
    // Where dompdf keeps fonts and temporary files.
    'cache' => $runtime->basePath . '/storage/pdf',
];

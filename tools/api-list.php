<?php

declare(strict_types=1);

// Prints the generated block of docs/API.md (every type tagged @api). Usage: php tools/api-list.php
// then paste it between the api-list markers, or run: php tools/api-list.php --write

require __DIR__ . '/../vendor/autoload.php';

use Trunk\Tests\Architecture\PublicApiTest;
use Trunk\Tests\Support\PublicApi;

$block = PublicApiTest::listing(new PublicApi()->types());

if (in_array('--write', $argv, true)) {
    $file = __DIR__ . '/../docs/API.md';
    $doc = is_file($file) ? (string) file_get_contents($file) : "<!-- api-list:start -->\n<!-- api-list:end -->";
    $updated = preg_replace('/<!-- api-list:start -->.*<!-- api-list:end -->/s', addcslashes($block, '\\$'), $doc);
    file_put_contents($file, $updated);
    fwrite(STDOUT, "docs/API.md updated\n");

    return;
}

fwrite(STDOUT, $block . "\n");

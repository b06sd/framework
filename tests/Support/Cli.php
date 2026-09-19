<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use RuntimeException;

/**
 * Runs a real PHP subprocess (tests only), so end-to-end tests exercise bin/trunk and public/index.php
 * exactly as a user would.
 */
final class Cli
{
    /**
     * @param non-empty-list<string> $argv
     * @param array<string, string>  $environment merged over the current environment
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    public function run(array $argv, string $cwd, array $environment = []): array
    {
        $env = [];

        foreach (getenv() as $name => $value) {
            $env[(string) $name] = $value;
        }

        unset($env['APP_ENV'], $env['APP_DEBUG']);

        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, [...$env, ...$environment]);

        if (!\is_resource($process)) {
            throw new RuntimeException('Unable to start ' . $argv[0]);
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}

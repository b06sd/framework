<?php

declare(strict_types=1);

namespace Trunk\Error;

/**
 * The last-resort log used when the normal logger cannot be trusted (a fatal error, memory
 * exhaustion, a crash while starting up). It allocates almost nothing, has no dependencies and
 * never throws: one JSON line appended to a file, or PHP's error_log when the file is unwritable.
 */
final readonly class EmergencyLog
{
    public function __construct(private string $path) {}

    /**
     * @param array<string, scalar|null> $fields short scalar facts only (type, file, line, request id, memory)
     */
    public function write(string $message, array $fields = []): void
    {
        $line = '{"timestamp":"' . gmdate('Y-m-d\TH:i:s\Z') . '","level":"CRITICAL","message":' . json_encode($message, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        foreach ($fields as $name => $value) {
            $line .= ',' . json_encode($name) . ':' . json_encode(\is_string($value) ? substr($value, 0, 500) : $value, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        $line .= "}\n";

        if (@file_put_contents($this->path, $line, \FILE_APPEND | \LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }
}

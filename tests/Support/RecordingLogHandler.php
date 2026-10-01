<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Trunk\Logging\LogHandler;

/**
 * Keeps the formatted lines a logger writes, and decodes JSON ones for assertions.
 */
final class RecordingLogHandler implements LogHandler
{
    /** @var list<string> */
    public array $lines = [];

    public function write(string $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * @return list<array<string, string>> the string fields of each JSON record
     */
    public function records(): array
    {
        $records = [];

        foreach ($this->lines as $line) {
            $data = json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
            $fields = [];

            foreach (\is_array($data) ? $data : [] as $key => $value) {
                if (\is_string($key) && \is_string($value)) {
                    $fields[$key] = $value;
                }
            }

            $records[] = $fields;
        }

        return $records;
    }
}

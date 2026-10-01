<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Source;

use RuntimeException;
use Trunk\Pipeline\Source;

/**
 * Streams a CSV file: the first row is used as column names, every later row becomes an associative
 * array. Never loads the file fully into memory (one fgetcsv() call at a time).
 *
 * Reopens and skips to $offset on every call, since a CSV has no cheap way to seek to "row N"
 * without an index. Fine for the moderate files most imports are; a source reading many millions of
 * rows scales better keyed on something indexed (see DatabaseSink's sibling read pattern in the ORM
 * guide's keyset cursor()) rather than row-count offset.
 *
 * @api
 */
final readonly class CsvSource implements Source
{
    public function __construct(private string $path, private string $separator = ',') {}

    public function read(int $offset, int $limit): iterable
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(\sprintf('"%s" could not be opened.', $this->path));
        }

        try {
            $header = fgetcsv($handle, separator: $this->separator, escape: '\\');

            if ($header === false) {
                return;
            }

            $columns = array_map(static fn(?string $name): string => $name ?? '', $header);

            for ($skipped = 0; $skipped < $offset; ++$skipped) {
                if (fgetcsv($handle, separator: $this->separator, escape: '\\') === false) {
                    return;
                }
            }

            for ($read = 0; $read < $limit; ++$read) {
                $row = fgetcsv($handle, separator: $this->separator, escape: '\\');

                if ($row === false) {
                    return;
                }

                yield array_combine($columns, $row);
            }
        } finally {
            fclose($handle);
        }
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Pipeline\Source;

use PHPUnit\Framework\TestCase;
use Trunk\Pipeline\Source\CsvSource;

final class CsvSourceTest extends TestCase
{
    private ?string $path = null;

    protected function tearDown(): void
    {
        if ($this->path !== null) {
            @unlink($this->path);
        }
    }

    public function test_the_header_row_becomes_the_keys_and_rows_are_chunked(): void
    {
        // Arrange
        $this->path = tempnam(sys_get_temp_dir(), 'trunk-csv-');
        file_put_contents($this->path, "name,email\nAda,ada@example.com\nGrace,grace@example.com\nAlan,alan@example.com\n");
        $source = new CsvSource($this->path);

        // Act
        $first = iterator_to_array($source->read(0, 2), false);
        $second = iterator_to_array($source->read(2, 2), false);

        // Assert
        self::assertSame([
            ['name' => 'Ada', 'email' => 'ada@example.com'],
            ['name' => 'Grace', 'email' => 'grace@example.com'],
        ], $first);
        self::assertSame([['name' => 'Alan', 'email' => 'alan@example.com']], $second, 'fewer than the limit signals exhaustion');
    }

    public function test_an_empty_file_yields_nothing(): void
    {
        // Arrange
        $this->path = tempnam(sys_get_temp_dir(), 'trunk-csv-');
        file_put_contents($this->path, '');

        // Act & Assert
        self::assertSame([], iterator_to_array(new CsvSource($this->path)->read(0, 10), false));
    }
}

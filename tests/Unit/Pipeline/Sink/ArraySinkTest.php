<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Pipeline\Sink;

use PHPUnit\Framework\TestCase;
use Trunk\Pipeline\Sink\ArraySink;

final class ArraySinkTest extends TestCase
{
    public function test_write_appends_every_call(): void
    {
        // Arrange
        $sink = new ArraySink();

        // Act
        $sink->write([1, 2]);
        $sink->write([3]);

        // Assert
        self::assertSame([1, 2, 3], $sink->all());
    }
}

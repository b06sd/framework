<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Pipeline\Source;

use PHPUnit\Framework\TestCase;
use Trunk\Pipeline\Source\ArraySource;

final class ArraySourceTest extends TestCase
{
    public function test_read_slices_by_offset_and_limit(): void
    {
        // Arrange
        $source = new ArraySource(['a', 'b', 'c', 'd', 'e']);

        // Act & Assert
        self::assertSame(['a', 'b'], iterator_to_array($source->read(0, 2), false));
        self::assertSame(['c', 'd'], iterator_to_array($source->read(2, 2), false));
        self::assertSame(['e'], iterator_to_array($source->read(4, 2), false), 'fewer than the limit signals exhaustion');
        self::assertSame([], iterator_to_array($source->read(5, 2), false));
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Pipeline;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Trunk\Pipeline\PipelineBuilder;
use Trunk\Pipeline\Sink\ArraySink;
use Trunk\Pipeline\Source\ArraySource;
use Trunk\Pipeline\Stage;

final class PipelineBuilderTest extends TestCase
{
    public function test_from_through_into_and_chunk_are_fluent_and_readable_back(): void
    {
        // Arrange
        $builder = new PipelineBuilder();
        $source = new ArraySource([1, 2, 3]);
        $sink = new ArraySink();
        $stage = new class implements Stage {
            public function process(mixed $record): mixed
            {
                return $record;
            }
        };

        // Act
        $result = $builder->from($source)->through($stage)->through($stage)->into($sink)->chunk(50);

        // Assert
        self::assertSame($builder, $result);
        self::assertSame($source, $builder->source());
        self::assertCount(2, $builder->stages());
        self::assertSame($sink, $builder->sink());
        self::assertSame(50, $builder->chunkSize());
    }

    public function test_the_default_chunk_size_is_five_hundred(): void
    {
        self::assertSame(500, new PipelineBuilder()->chunkSize());
    }

    public function test_a_chunk_size_below_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PipelineBuilder()->chunk(0);
    }

    public function test_source_without_from_is_a_clear_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a source');
        new PipelineBuilder()->source();
    }

    public function test_sink_without_into_is_a_clear_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a sink');
        new PipelineBuilder()->sink();
    }
}

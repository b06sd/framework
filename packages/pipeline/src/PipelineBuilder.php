<?php

declare(strict_types=1);

namespace Trunk\Pipeline;

use InvalidArgumentException;

/**
 * Collects what a Pipeline's define() describes: a source, zero or more stages, a sink and a chunk
 * size. Fluent, mirroring MapBuilder's shape — one builder, filled in by one method.
 *
 * @api
 */
final class PipelineBuilder
{
    private ?Source $source = null;

    /** @var list<Stage> */
    private array $stages = [];

    private ?Sink $sink = null;

    private int $chunkSize = 500;

    public function from(Source $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function through(Stage $stage): static
    {
        $this->stages[] = $stage;

        return $this;
    }

    public function into(Sink $sink): static
    {
        $this->sink = $sink;

        return $this;
    }

    public function chunk(int $size): static
    {
        if ($size < 1) {
            throw new InvalidArgumentException('chunk() needs a size of one or more.');
        }

        $this->chunkSize = $size;

        return $this;
    }

    public function source(): Source
    {
        return $this->source ?? throw new InvalidArgumentException('A pipeline needs a source: call from().');
    }

    /**
     * @return list<Stage>
     */
    public function stages(): array
    {
        return $this->stages;
    }

    public function sink(): Sink
    {
        return $this->sink ?? throw new InvalidArgumentException('A pipeline needs a sink: call into().');
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }
}

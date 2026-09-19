<?php

declare(strict_types=1);

namespace Trunk\Error;

use Throwable;

/**
 * Builds DebugInfo: location, a few source lines, and a trace without arguments (arguments can hold
 * passwords and tokens, so they are never read).
 */
final readonly class DebugInfoFactory
{
    private const int CONTEXT_LINES = 5;

    private const int MAX_FRAMES = 30;

    private const int MAX_FILE_BYTES = 1_000_000;

    public function create(Throwable $error): DebugInfo
    {
        $previous = [];

        for ($p = $error->getPrevious(); $p !== null && \count($previous) < 3; $p = $p->getPrevious()) {
            $previous[] = $p::class . ': ' . $p->getMessage();
        }

        [$file, $line] = $this->origin($error);

        return new DebugInfo(
            $error::class,
            $error->getMessage(),
            $error instanceof DeveloperHint ? $error->hint() : null,
            $file,
            $line,
            $this->snippet($file, $line),
            $this->trace($error),
            $previous,
        );
    }

    /**
     * The file and line to show: the source of the nearest exception that knows one (a template),
     * otherwise where PHP threw.
     *
     * @return array{string, int}
     */
    private function origin(Throwable $error): array
    {
        for ($e = $error, $depth = 0; $e !== null && $depth < 5; $e = $e->getPrevious(), ++$depth) {
            if ($e instanceof SourceLocated && $e->sourceFile() !== null && $e->sourceLine() !== null) {
                return [$e->sourceFile(), $e->sourceLine()];
            }
        }

        return [$error->getFile(), $error->getLine()];
    }

    /**
     * @return list<array{line: int, code: string, current: bool}>
     */
    private function snippet(string $file, int $line): array
    {
        if (!is_file($file) || !is_readable($file) || (int) filesize($file) > self::MAX_FILE_BYTES) {
            return [];
        }

        $lines = file($file, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return [];
        }

        $from = max(1, $line - self::CONTEXT_LINES);
        $to = min(\count($lines), $line + self::CONTEXT_LINES);
        $snippet = [];

        for ($i = $from; $i <= $to; ++$i) {
            $snippet[] = ['line' => $i, 'code' => $lines[$i - 1], 'current' => $i === $line];
        }

        return $snippet;
    }

    /**
     * @return list<string>
     */
    private function trace(Throwable $error): array
    {
        $frames = [];

        foreach (\array_slice($error->getTrace(), 0, self::MAX_FRAMES) as $i => $frame) {
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()';
            $frames[] = '#' . $i . ' ' . (isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? 0) . ' ' : '') . $call;
        }

        return $frames;
    }
}

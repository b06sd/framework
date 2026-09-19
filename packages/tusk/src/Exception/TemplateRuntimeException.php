<?php

declare(strict_types=1);

namespace Trunk\Tusk\Exception;

use RuntimeException;
use Throwable;
use Trunk\Error\SourceLocated;
use Trunk\Tusk\Loader\SourceLocator;

/**
 * A template failed while rendering. When the failing statement can be traced to its source, the
 * message names the template file and line (`users/show.tusk.php:14`), not the generated PHP.
 *
 * @api
 */
final class TemplateRuntimeException extends RuntimeException implements SourceLocated
{
    private const int MAX_FRAMES = 25;

    public function __construct(string $message, int $code = 0, ?Throwable $previous = null, public readonly ?string $sourceTemplate = null, public readonly ?int $sourceLine = null, private readonly ?string $sourcePath = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function sourceFile(): ?string
    {
        return $this->sourcePath;
    }

    public function sourceLine(): ?int
    {
        return $this->sourceLine;
    }
    /** @internal used by the renderer */

    public static function in(string $template, Throwable $previous, ?SourceLocator $sources = null): self
    {
        if ($previous instanceof self && $previous->sourceTemplate !== null) {
            return new self(\sprintf('Error while rendering "%s": %s', $template, $previous->getMessage()), 0, $previous, $previous->sourceTemplate, $previous->sourceLine, $previous->sourcePath);
        }

        $location = self::locate($previous);

        if ($location === null) {
            return new self(\sprintf('Error while rendering "%s": %s', $template, $previous->getMessage()), 0, $previous);
        }

        [$source, $line] = $location;

        return new self(\sprintf('Error while rendering "%s" at %s.tusk.php:%d: %s', $template, $source, $line, $previous->getMessage()), 0, $previous, $source, $line, $sources?->sourcePath($source));
    }

    /**
     * Finds the innermost compiled-template frame of an exception and reads the source location
     * recorded in the generated file (`// tusk-template:` header and `// tusk:LINE` markers).
     *
     * @return array{string, int}|null template name and template line
     */
    private static function locate(Throwable $error): ?array
    {
        $frames = [['file' => $error->getFile(), 'line' => $error->getLine()], ...\array_slice($error->getTrace(), 0, self::MAX_FRAMES)];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;

            if (!\is_string($file) || !\is_int($line) || !is_file($file) || filesize($file) > 2_000_000) {
                continue;
            }

            $lines = file($file, \FILE_IGNORE_NEW_LINES);

            if ($lines === false || !preg_match('/^\/\/ tusk-template: ([A-Za-z0-9_\/.?-]+)$/D', $lines[4] ?? '', $header)) {
                continue;
            }

            for ($i = min($line, \count($lines)) - 1; $i >= 0; --$i) {
                if (preg_match('/ \/\/ tusk:(\d+)$/D', $lines[$i], $marker) === 1) {
                    return [$header[1], (int) $marker[1]];
                }
            }

            return [$header[1], 1];
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Console\Output;

use Trunk\Contracts\Console\CommandOutput;

/**
 * Writes to two streams (out, error). Colour is only used when told to; `forTerminal()` enables it
 * for a real TTY and honours NO_COLOR.
 */
final class Output implements CommandOutput
{
    /**
     * @param resource $out
     * @param resource $error
     */
    public function __construct(private readonly mixed $out, private readonly mixed $error, private readonly bool $ansi = false) {}

    public static function forTerminal(): self
    {
        $noColor = getenv('NO_COLOR');
        $ansi = ($noColor === false || $noColor === '') && \defined('STDOUT') && stream_isatty(\STDOUT);

        return new self(\STDOUT, \STDERR, $ansi);
    }

    public function write(string $text): void
    {
        fwrite($this->out, $text);
    }

    public function line(string $text = ''): void
    {
        $this->write($text . "\n");
    }

    public function title(string $text): void
    {
        $this->line($this->style($text, '1'));
    }

    public function info(string $text): void
    {
        $this->line($this->style($text, '36'));
    }

    public function success(string $text): void
    {
        $this->line($this->style('✓ ', '32') . $text);
    }

    public function warning(string $text): void
    {
        $this->line($this->style('! ', '33') . $text);
    }

    public function failure(string $text): void
    {
        $this->line($this->style('✗ ', '31') . $text);
    }

    public function error(string $text): void
    {
        fwrite($this->error, $this->style($text, '31') . "\n");
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map(mb_strlen(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }

        $this->line($this->style($this->row($headers, $widths), '1'));
        $this->line(implode('  ', array_map(static fn(int $w): string => str_repeat('-', $w), $widths)));

        foreach ($rows as $row) {
            $this->line($this->row($row, $widths));
        }
    }

    /**
     * @param list<string> $cells
     * @param array<int, int> $widths
     */
    private function row(array $cells, array $widths): string
    {
        $padded = [];

        foreach ($widths as $i => $width) {
            $padded[] = str_pad($cells[$i] ?? '', $width);
        }

        return rtrim(implode('  ', $padded));
    }

    private function style(string $text, string $code): string
    {
        return $this->ansi ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }
}

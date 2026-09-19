<?php

declare(strict_types=1);

namespace Trunk\Foundation\Project;

use Trunk\Foundation\Exception\ProjectException;

/**
 * Reads the project's `.env` file: where application settings live (APP_ENV, APP_DEBUG, APP_PORT,
 * secrets, service addresses...). Real environment variables always win over the file, so
 * production can be configured by the platform and the file is optional.
 *
 * The format is deliberately small: `KEY=value`, `KEY="double quoted"` (with \n \t \" \\),
 * `KEY='literal'`, `# comments`, optional `export `. There is no variable interpolation and no
 * multi-line value, so a value can never execute or expand into something unexpected.
 */
final class EnvironmentFile
{
    private const int MAX_BYTES = 65536;

    public function exists(string $basePath): bool
    {
        return is_file($basePath . '/.env');
    }

    /**
     * @param array<string, string> $real the real process environment (`getenv()`), which takes precedence
     *
     * @return array<string, string>
     */
    public function load(string $basePath, array $real = []): array
    {
        $file = $basePath . '/.env';
        $variables = is_file($file) ? $this->parse((string) file_get_contents($file)) : [];

        return [...$variables, ...$real];
    }

    /**
     * @return array<string, string>
     */
    public function parse(string $contents): array
    {
        if (\strlen($contents) > self::MAX_BYTES) {
            throw new ProjectException('.env is larger than 64 KB, which is almost certainly a mistake.');
        }

        $variables = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            $number = $index + 1;
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/D', $line, $m) !== 1) {
                throw new ProjectException(\sprintf('.env line %d is not in the form KEY=value.', $number));
            }

            $value = $this->value($m[2], $number);

            if (str_contains($value, "\0")) {
                throw new ProjectException(\sprintf('.env line %d contains a NUL byte.', $number));
            }

            $variables[$m[1]] = $value;
        }

        return $variables;
    }

    private function value(string $raw, int $line): string
    {
        if ($raw !== '' && $raw[0] === '"') {
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(?:#.*)?$/D', $raw, $m) !== 1) {
                throw new ProjectException(\sprintf('.env line %d has an unterminated or malformed double-quoted value.', $line));
            }

            return strtr($m[1], ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
        }

        if ($raw !== '' && $raw[0] === "'") {
            if (preg_match("/^'([^']*)'\\s*(?:#.*)?$/D", $raw, $m) !== 1) {
                throw new ProjectException(\sprintf('.env line %d has an unterminated or malformed single-quoted value.', $line));
            }

            return $m[1];
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $raw));
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Console\Input;

use Trunk\Contracts\Console\CommandInput;

/**
 * Parsed command line: `trunk <command> [arguments] [--option=value] [--flag] [-v] [-h]`.
 * Everything after a bare `--` is passed through as arguments untouched.
 */
final readonly class Input implements CommandInput
{
    /**
     * @param list<string>                $arguments
     * @param array<string, string|true>  $options
     */
    public function __construct(
        public ?string $command,
        public array $arguments = [],
        public array $options = [],
    ) {}

    /**
     * @param list<string> $argv the full argv, script name first
     */
    public static function fromArgv(array $argv): self
    {
        $tokens = \array_slice($argv, 1);
        $command = null;
        $arguments = [];
        $options = [];
        $passthrough = false;

        foreach ($tokens as $token) {
            if ($passthrough) {
                $arguments[] = $token;
            } elseif ($token === '--') {
                $passthrough = true;
            } elseif (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                $equals = strpos($body, '=');

                if ($equals === false) {
                    $options[$body] = true;
                } else {
                    $options[substr($body, 0, $equals)] = substr($body, $equals + 1);
                }
            } elseif (preg_match('/^-[a-zA-Z]+$/D', $token) === 1) {
                foreach (str_split(substr($token, 1)) as $short) {
                    $options[$short] = true;
                }
            } elseif ($command === null) {
                $command = $token;
            } else {
                $arguments[] = $token;
            }
        }

        return new self($command, $arguments, $options);
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return \is_string($value) ? $value : $default;
    }

    public function arguments(): array
    {
        return $this->arguments;
    }

    public function flag(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function wantsHelp(): bool
    {
        return $this->flag('help') || $this->flag('h');
    }

    public function wantsVersion(): bool
    {
        return $this->flag('version') || $this->flag('V');
    }

    public function verbose(): bool
    {
        return $this->flag('verbose') || $this->flag('v');
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Console\Process;

use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Project\Project;

/**
 * Builds the two child processes the CLI may start: the PHP development server and PHPUnit.
 * Executables are fixed, arguments are validated, and everything is passed as an array.
 */
final readonly class ProcessRunner
{
    public function __construct(private ProcessLauncher $launcher = new ProcOpenLauncher()) {}

    /**
     * @return non-empty-list<string>
     */
    public function serveCommand(Project $project, string $host, int $port): array
    {
        if (preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9.-]*|\[[0-9A-Fa-f:]+\])$/D', $host) !== 1) {
            throw new CommandFailedException(\sprintf('"%s" is not a valid host. Use a host name or IP address such as 127.0.0.1.', $host));
        }

        if ($port < 1 || $port > 65535) {
            throw new CommandFailedException('The port must be between 1 and 65535.');
        }

        $router = $project->path('public/index.php');

        if (!is_file($router)) {
            throw new CommandFailedException('This project has no public/index.php, so there is nothing to serve. (CLI and worker applications do not serve HTTP.)');
        }

        return [\PHP_BINARY, '-S', $host . ':' . $port, '-t', $project->path('public'), $router];
    }

    /**
     * @param list<string> $arguments passed straight to PHPUnit
     *
     * @return non-empty-list<string>
     */
    public function testCommand(Project $project, array $arguments): array
    {
        $phpunit = $project->path('vendor/bin/phpunit');

        if (!is_file($phpunit)) {
            throw new CommandFailedException('PHPUnit is not installed. Run `composer install` (it is a dev dependency of every Trunk project).');
        }

        foreach ($arguments as $argument) {
            if (str_contains($argument, "\0")) {
                throw new CommandFailedException('Arguments must not contain NUL bytes.');
            }
        }

        return [\PHP_BINARY, $phpunit, ...$arguments];
    }

    /**
     * `composer require` for one validated package (`vendor/name` or `vendor/name:constraint`).
     *
     * @return non-empty-list<string>
     */
    public function composerRequireCommand(string $package): array
    {
        return ['composer', 'require', '--no-interaction', '--', $this->packageSpec($package)];
    }

    /**
     * One `composer require` for several validated packages.
     *
     * @param non-empty-list<string> $packages `vendor/name:constraint` specs
     *
     * @return non-empty-list<string>
     */
    public function composerRequireAllCommand(array $packages): array
    {
        return ['composer', 'require', '--no-interaction', '--', ...array_map($this->packageSpec(...), $packages)];
    }

    /**
     * @return non-empty-list<string>
     */
    public function composerRemoveCommand(string $package): array
    {
        return ['composer', 'remove', '--no-interaction', '--', $this->packageName($package)];
    }

    public function composerRequire(Project $project, string $package): int
    {
        return $this->launcher->launch($this->composerRequireCommand($package), $project->basePath, $this->realEnvironment());
    }

    /**
     * @param non-empty-list<string> $packages
     */
    public function composerRequireAll(Project $project, array $packages): int
    {
        return $this->launcher->launch($this->composerRequireAllCommand($packages), $project->basePath, $this->realEnvironment());
    }

    public function composerRemove(Project $project, string $package): int
    {
        return $this->launcher->launch($this->composerRemoveCommand($package), $project->basePath, $this->realEnvironment());
    }

    public function isPackageSpec(string $value): bool
    {
        return preg_match('#^[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:[_.-]?[a-z0-9]+)*(?::[A-Za-z0-9^~*.@|<>=,-]{1,64})?$#D', $value) === 1 && !str_starts_with($value, '-');
    }

    public function packageName(string $spec): string
    {
        return explode(':', $this->packageSpec($spec), 2)[0];
    }

    public function serve(Project $project, string $host, int $port): int
    {
        return $this->launcher->launch($this->serveCommand($project, $host, $port), $project->basePath, $this->environment());
    }

    /**
     * @param list<string> $arguments
     */
    public function test(Project $project, array $arguments): int
    {
        return $this->launcher->launch($this->testCommand($project, $arguments), $project->basePath, $this->environment());
    }

    private function packageSpec(string $package): string
    {
        if (!$this->isPackageSpec($package)) {
            throw new CommandFailedException(\sprintf('"%s" is not a valid Composer package (expected vendor/name or vendor/name:constraint).', $package));
        }

        return $package;
    }

    /**
     * @return array<string, string>
     */
    private function realEnvironment(): array
    {
        $environment = [];

        foreach (getenv() as $name => $value) {
            $environment[(string) $name] = $value;
        }

        return $environment;
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $environment = [];

        foreach (getenv() as $name => $value) {
            $environment[(string) $name] = $value;
        }

        return [...$environment, 'APP_ENV' => 'local', 'APP_DEBUG' => '1'];
    }
}

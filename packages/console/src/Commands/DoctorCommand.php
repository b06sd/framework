<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Closure;
use Throwable;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Foundation\Capability\CapabilityCatalog;
use Trunk\Foundation\Capability\ComposerRequirements;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleGraph;
use Trunk\Foundation\Project\ConfigurationLoader;
use Trunk\Foundation\Project\Project;
use Trunk\Foundation\Runtime;

final readonly class DoctorCommand implements Command
{
    /**
     * @param array<string, string> $variables
     */
    public function __construct(
        private Project $project,
        private array $variables = [],
        private bool $hasEnvFile = false,
        private ConfigurationLoader $configuration = new ConfigurationLoader(),
        private ?Closure $pcntlAvailable = null,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('doctor', 'Check that this project is healthy');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $problems = 0;
        $output->title('Trunk doctor: ' . $this->project->name . ' (' . $this->project->type . ')');
        $output->line();

        $php = $this->atLeast(80400, \PHP_VERSION_ID);
        $problems += $this->report($output, $php, 'PHP ' . \PHP_VERSION, $php ? '' : 'Trunk needs PHP 8.4 or newer.');

        $missing = array_values(array_filter(['mbstring', 'json', 'ctype', 'tokenizer'], static fn(string $e): bool => !\extension_loaded($e)));
        $problems += $this->report($output, $missing === [], 'PHP extensions', $missing === [] ? '' : 'Missing: ' . implode(', ', $missing));

        $installed = is_file($this->project->path('vendor/autoload.php'));
        $problems += $this->report($output, $installed, 'Composer dependencies installed', 'Run `composer install`.');

        if ($installed) {
            $requirements = new ComposerRequirements();
            $missingDependencies = $requirements->missing($this->project->basePath, $requirements->for(new CapabilityCatalog($this->project->basePath)->enabled($this->project->modules)));
            $problems += $this->report($output, $missingDependencies === [], 'Capability dependencies', $requirements->describe($missingDependencies));
        }

        try {
            $this->configuration->evaluate($this->project, new Runtime(Environment::Local, true, $this->project->basePath));
            $problems += $this->report($output, true, 'Configuration (config/*.php)', '');
        } catch (Throwable $e) {
            $problems += $this->report($output, false, 'Configuration (config/*.php)', $e->getMessage());
        }

        $env = Environment::fromString($this->variables['APP_ENV'] ?? null);
        $output->success($this->hasEnvFile ? '.env found (real environment variables override it)' : 'No .env file (using the real environment only)');
        $output->success('Environment: ' . $env->value . ($env === Environment::Production ? ' (compiled build required)' : ' (development container)'));
        $problems += $this->build($output, $env);

        $problems += $this->capabilities($output);
        $problems += $this->diagnostics($output, $env);

        $output->line();
        $problems === 0 ? $output->success('Project is healthy.') : $output->failure(\sprintf('%d problem(s) found.', $problems));

        return $problems === 0 ? 0 : 1;
    }

    private function capabilities(CommandOutput $output): int
    {
        $catalog = new CapabilityCatalog($this->project->basePath);
        $modules = $this->project->modules;
        $problems = 0;
        $output->line();
        $output->line('Capabilities (from trunk.php):');

        foreach ($catalog->enabled($modules) as $capability) {
            $output->line(\sprintf('  + %s  [%s]', $capability->name . ' - ' . $capability->description, $capability->package));
        }

        foreach ($catalog->enabled($modules) as $capability) {
            if (!$capability->isBuiltIn()) {
                $output->warning(\sprintf('%s comes from third-party package %s; its modules run with the full privileges of your application, so review it like any dependency.', $capability->name, $capability->package));
            }
        }

        foreach ($catalog->unclaimedModules($modules) as $module) {
            $output->line('  + Application module  [' . $module . ']');
        }

        foreach ($catalog->partiallyEnabled($modules) as $capability) {
            $output->failure(\sprintf('%s is only partly enabled: trunk.php lists some of its modules (%s) but not all.', $capability->name, implode(', ', $capability->modules)));
            ++$problems;
        }

        foreach ($catalog->missingRequirements($modules) as [$capability, $missing]) {
            $output->failure(\sprintf('%s needs "%s", which is not enabled. Fix: trunk package:install %s', $capability->name, $missing, $missing));
            ++$problems;
        }

        $enabled = $catalog->enabled($modules);

        foreach ($catalog->all() as $capability) {
            if (!\in_array($capability, $enabled, true) && !\in_array($capability, $catalog->partiallyEnabled($modules), true)) {
                $output->line(\sprintf('  - %s  (%s; enable with: trunk package:install %s)', $capability->name, $capability->isBuiltIn() ? 'not enabled' : 'installed, not enabled', $capability->id));
            }
        }

        $wanted = $catalog->integrationModules($modules);
        $present = array_intersect($catalog->allIntegrationModules(), $modules);

        if (array_diff($wanted, $modules) !== [] || array_diff($present, $wanted) !== []) {
            $output->warning('Capability integrations are out of sync (for example a command module is missing or stale). Fix: trunk package:sync');
        }

        foreach ($catalog->problems() as $problem) {
            $output->warning($problem);
        }

        return $problems;
    }

    /**
     * Checks for the error, logging and worker setup.
     */
    private function diagnostics(CommandOutput $output, Environment $env): int
    {
        $modules = $this->project->modules;
        $problems = 0;

        foreach (new ModuleGraph()->errors($modules) as $error) {
            $output->failure($error);
            ++$problems;
        }

        $enabled = array_map(static fn($c): string => $c->id, new CapabilityCatalog($this->project->basePath)->enabled($modules));

        if (\in_array('logging', $enabled, true)) {
            $directory = $this->project->path('storage/logs');
            $writable = is_dir($directory) ? is_writable($directory) : is_writable($this->project->path('storage'));
            $channel = $this->variables['LOG_CHANNEL'] ?? ($env === Environment::Production ? 'stderr' : 'file');
            $problems += $channel === 'file' ? $this->report($output, $writable, 'Log directory (storage/logs) is writable', 'Create it and make it writable, or set LOG_CHANNEL=stderr.') : $this->report($output, true, 'Logging to ' . $channel, '');
        }

        if ($env === Environment::Production && !\in_array($this->variables['APP_DEBUG'] ?? '', ['', '0'], true)) {
            $output->warning('APP_DEBUG is set, but it is ignored in production: error details are never shown to clients.');
        }

        foreach (['.env' => 'holds secrets in development', 'build/config.php' => 'is a build artifact that may hold configuration'] as $file => $why) {
            $path = $this->project->path($file);

            if (is_file($path) && (fileperms($path) & 0o004) !== 0) {
                $output->warning(\sprintf('%s is readable by every user on this machine and %s. Fix: chmod 640 %s', $file, $why, $file));
            }
        }

        $logs = $this->project->path('storage/logs');

        if (is_dir($logs) && (fileperms($logs) & 0o002) !== 0) {
            $output->warning('storage/logs is writable by every user on this machine. Fix: chmod 750 storage/logs');
        }

        if (\in_array('queue', $enabled, true) && !($this->pcntlAvailable !== null ? ($this->pcntlAvailable)() : \function_exists('pcntl_alarm'))) {
            $output->warning('ext-pcntl is not installed: queue workers cannot enforce job timeouts or stop gracefully on SIGTERM.');
        }

        return $problems;
    }

    private function atLeast(int $required, int $actual): bool
    {
        return $actual >= $required;
    }

    private function build(CommandOutput $output, Environment $env): int
    {
        $modulesFile = $this->project->buildDirectory() . '/modules.php';

        if (!is_file($modulesFile)) {
            $env === Environment::Production ? $output->failure('Build: missing. Run `trunk build`.') : $output->warning('Build: none yet (only needed for production). Run `trunk build`.');

            return $env === Environment::Production ? 1 : 0;
        }

        $built = require $modulesFile;
        $current = $built === $this->project->modules;
        $newest = max(filemtime($this->project->path('trunk.php')) ?: 0, ...array_map(static fn(string $f): int => filemtime($f) ?: 0, glob($this->project->configDirectory() . '/*.php') ?: []));
        $fresh = $current && $newest <= (filemtime($modulesFile) ?: 0);
        $fresh ? $output->success('Build: up to date.') : $output->warning('Build: out of date (trunk.php or config changed). Run `trunk build`.');

        return 0;
    }

    private function report(CommandOutput $output, bool $ok, string $label, string $detail): int
    {
        $ok ? $output->success($label) : $output->failure($label . ($detail === '' ? '' : ': ' . $detail));

        return $ok ? 0 : 1;
    }
}

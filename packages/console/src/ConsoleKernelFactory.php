<?php

declare(strict_types=1);

namespace Trunk\Console;

use Closure;
use Throwable;
use Trunk\Console\Commands\BuildCommand;
use Trunk\Console\Commands\DoctorCommand;
use Trunk\Console\Commands\MakeCommand;
use Trunk\Console\Commands\NewCommand;
use Trunk\Console\Commands\PackageInstallCommand;
use Trunk\Console\Commands\PackageListCommand;
use Trunk\Console\Commands\PackageRemoveCommand;
use Trunk\Console\Commands\PackageSyncCommand;
use Trunk\Console\Commands\ProjectBoundCommand;
use Trunk\Console\Commands\RouteListCommand;
use Trunk\Console\Commands\ServeCommand;
use Trunk\Console\Commands\TestCommand;
use Trunk\Console\Output\Output;
use Trunk\Console\Process\ProcessRunner;
use Trunk\Console\Scaffold\Generator;
use Trunk\Console\Scaffold\ProjectScaffolder;
use Trunk\Contracts\Console\Command;
use Trunk\Foundation\Capability\CapabilityManager;
use Trunk\Foundation\Project\ApplicationFactory;
use Trunk\Foundation\Project\EnvironmentFile;
use Trunk\Foundation\Project\Project;
use Trunk\Foundation\Project\ProjectLoader;

/**
 * Wires the console: `new` always works; the other built-ins need a project (found by walking up
 * to trunk.php); application commands are booted lazily, only when something asks for them.
 */
final readonly class ConsoleKernelFactory
{
    public function __construct(
        private ProjectLoader $projects = new ProjectLoader(),
        private ApplicationFactory $applications = new ApplicationFactory(),
        private ProcessRunner $processes = new ProcessRunner(),
        private EnvironmentFile $environment = new EnvironmentFile(),
    ) {}

    /**
     * @param array<string, string> $realEnvironment the process environment (`getenv()`); it is merged over the project's .env
     */
    public function create(string $workingDirectory, Output $output, array $realEnvironment = []): ConsoleKernel
    {
        $root = $this->projects->locate($workingDirectory);
        $project = null;
        $problem = null;

        if ($root !== null) {
            try {
                $project = $this->projects->load($root);
            } catch (Throwable $e) {
                $problem = $e;
            }
        }

        $variables = $root === null ? $realEnvironment : $this->environment->load($root, $realEnvironment);
        $hasEnvFile = $root !== null && $this->environment->exists($root);
        $builtIn = ['new' => static fn(): Command => new NewCommand(new ProjectScaffolder(), $workingDirectory)];
        $needsProject = fn(Closure $make): Closure => $this->bound($make, $project, $problem);

        $builtIn['build'] = $needsProject(static fn(Project $p): Command => new BuildCommand($p, $variables));
        $builtIn['doctor'] = $needsProject(static fn(Project $p): Command => new DoctorCommand($p, $variables, $hasEnvFile));
        $builtIn['route:list'] = $needsProject(static fn(Project $p): Command => new RouteListCommand($p));
        $builtIn['serve'] = $needsProject(fn(Project $p): Command => new ServeCommand($p, $this->processes, $variables));
        $builtIn['package:list'] = $needsProject(static fn(Project $p): Command => new PackageListCommand($p));
        $builtIn['package:sync'] = $needsProject(static fn(Project $p): Command => new PackageSyncCommand($p));
        $builtIn['package:install'] = $needsProject(fn(Project $p): Command => new PackageInstallCommand($p, new CapabilityManager(), $this->processes));
        $builtIn['package:remove'] = $needsProject(fn(Project $p): Command => new PackageRemoveCommand($p, new CapabilityManager(), $this->processes));
        $builtIn['test'] = $needsProject(fn(Project $p): Command => new TestCommand($p, $this->processes));

        foreach (Generator::KINDS as $kind) {
            $builtIn['make:' . $kind] = $needsProject(static fn(Project $p): Command => new MakeCommand($p, new Generator(), $kind));
        }

        $applicationCommands = $project === null
            ? null
            : fn(): CommandSet => new ApplicationCommands($project, $this->applications->runtime($project, $variables), $this->applications)->load();

        return new ConsoleKernel($builtIn, $applicationCommands, $output);
    }

    /**
     * @param Closure(Project): Command $make
     *
     * @return Closure(): Command
     */
    private function bound(Closure $make, ?Project $project, ?Throwable $problem): Closure
    {
        return static fn(): Command => new ProjectBoundCommand($make, $project, $problem);
    }
}

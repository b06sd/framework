<?php

declare(strict_types=1);

namespace Trunk\Foundation\Project;

use Trunk\Application\Application;
use Trunk\Compiler\ContainerCompiler;
use Trunk\Container\CompiledContainer;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Exception\ProjectException;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;

/**
 * Creates a booted Application for a project, in the mode its environment implies:
 * production => the compiled build (and it refuses to run without one), anything else => the
 * development container with configuration read from `config/`.
 */
final readonly class ApplicationFactory
{
    public function __construct(private ConfigurationLoader $configuration = new ConfigurationLoader()) {}

    /**
     * @param array<string, string> $variables real environment merged over .env (see EnvironmentFile::load)
     */
    public function runtime(Project $project, array $variables): Runtime
    {
        $debug = $variables['APP_DEBUG'] ?? null;
        $debugEnabled = $debug !== null && \in_array(strtolower($debug), ['1', 'true', 'on', 'yes'], true);

        return new Runtime(Environment::fromString($variables['APP_ENV'] ?? null), $debugEnabled, $project->basePath, $variables);
    }

    public function create(Project $project, Runtime $runtime): Application
    {
        $manifest = new ModuleManifest($project->modules);
        $application = $runtime->environment === Environment::Production
            ? $this->compiled($project, $runtime, $manifest)
            : new Application($runtime, $this->configuration->fromDirectory($project, $runtime), $manifest);

        $application->register();
        $application->boot();

        return $application;
    }

    private function compiled(Project $project, Runtime $runtime, ModuleManifest $manifest): Application
    {
        $build = $project->buildDirectory();

        if (!is_file($build . '/container.php') || !is_file($build . '/config.php')) {
            throw new ProjectException('This application has not been built for production. Run `trunk build` first, or set APP_ENV=local to run in development mode.');
        }

        require_once $build . '/container.php';
        $class = ContainerCompiler::NAMESPACE . '\\AppContainer';

        if (!is_subclass_of($class, CompiledContainer::class)) {
            throw new ProjectException('build/container.php is not a valid compiled container. Run `trunk build` again.');
        }

        return new Application($runtime, Configuration::fromFile($build . '/config.php', $runtime->variables), $manifest, $class);
    }
}

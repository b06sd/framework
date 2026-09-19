<?php

declare(strict_types=1);

namespace Trunk\Http\Kernel;

use Psr\Log\LoggerInterface;
use Throwable;
use Trunk\Error\EmergencyLog;
use Trunk\Error\ErrorHandlers;
use Trunk\Error\ExceptionHandler;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Project\ApplicationFactory;
use Trunk\Foundation\Project\EnvironmentFile;
use Trunk\Foundation\Project\ProjectLoader;
use Trunk\Http\Emitter\ResponseEmitter;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Middleware\ErrorHandlingMiddleware;
use Trunk\Logging\ContextHolder;

/**
 * The web front controller in one call (used by the generated public/index.php):
 * project -> application (compiled in production, development otherwise) -> HTTP kernel -> run.
 * Anything that fails while starting up becomes a generic 500; details appear only when APP_DEBUG
 * is on outside production, and are always sent to the error log.
 */
final readonly class WebEntry
{
    public function __construct(
        private ProjectLoader $projects = new ProjectLoader(),
        private ApplicationFactory $applications = new ApplicationFactory(),
        private EnvironmentFile $environment = new EnvironmentFile(),
    ) {}

    public function run(string $basePath): void
    {
        $debug = false;
        $handlers = new ErrorHandlers(new EmergencyLog($basePath . '/storage/logs/emergency.log'), false, 'http');
        $handlers->register();

        try {
            $project = $this->projects->load($basePath);
            $runtime = $this->applications->runtime($project, $this->environment->load($basePath, getenv()));
            $debug = $runtime->debug;
            $application = $this->applications->create($project, $runtime);
            $handlers->attach($this->service($application->container(), LoggerInterface::class), $this->service($application->container(), ExceptionHandler::class), $this->service($application->container(), ContextHolder::class), $debug);
            $kernels = new HttpKernelFactory();
            $kernel = $runtime->environment === Environment::Production
                ? $kernels->compiled($application, $project->buildDirectory())
                : $kernels->development($application, new ModuleManifest($project->modules));

            $kernel->run();
        } catch (Throwable $e) {
            error_log($e::class . ': ' . $e->getMessage());
            new ResponseEmitter()->emit(new ErrorHandlingMiddleware(new HttpFactory(), $debug)->respond($e));
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T|null
     */
    private function service(\Psr\Container\ContainerInterface $container, string $id): ?object
    {
        $service = $container->has($id) ? $container->get($id) : null;

        return $service instanceof $id ? $service : null;
    }
}

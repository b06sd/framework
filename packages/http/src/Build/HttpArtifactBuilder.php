<?php

declare(strict_types=1);

namespace Trunk\Http\Build;

use Trunk\Compiler\ArtifactWriter;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Http\Pipeline\MiddlewareCollector;
use Trunk\Http\Pipeline\MiddlewareProvider;
use Trunk\Http\Routing\RouteMiddlewareValidator;
use Trunk\Router\Compiler\RouteArtifactWriter;
use Trunk\Router\Exception\RouteCompilationException;
use Trunk\Support\FileWriter;

/**
 * Build-time only. Compiles container, routes and middleware, cross-validates them, and only
 * then writes container.php, modules.php, routes.php and pipeline.php. Nothing is written if any
 * check fails, so a broken application can never reach production as a partial build.
 */
final readonly class HttpArtifactBuilder
{
    public function __construct(
        private ArtifactWriter $container = new ArtifactWriter(),
        private RouteArtifactWriter $routes = new RouteArtifactWriter(),
        private FileWriter $files = new FileWriter(),
    ) {}

    /**
     * Compiles routes and collects middleware without touching the filesystem.
     *
     * @throws CompilationException
     */
    public function plan(ModuleManifest $manifest): HttpPlan
    {
        try {
            $table = $this->routes->compile($manifest);
        } catch (RouteCompilationException $e) {
            throw new CompilationException($e->errors);
        }

        $problems = new RouteMiddlewareValidator()->errors($table);

        if ($problems !== []) {
            throw new CompilationException($problems);
        }

        return new HttpPlan($table, $this->middleware($manifest));
    }

    /**
     * Writes routes.php and pipeline.php.
     *
     * @throws CompilationException
     */
    public function writeArtifacts(HttpPlan $plan, string $directory): void
    {
        try {
            $this->routes->writeTable($plan->routes, $directory);
        } catch (RouteCompilationException $e) {
            throw new CompilationException($e->errors);
        }

        $this->files->write($directory . '/pipeline.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($plan->middleware, true) . ";\n");
    }

    /**
     * Everything at once: container, routes and pipeline, validated together and written only if all are valid.
     *
     * @throws CompilationException
     */
    public function build(ModuleManifest $manifest, string $directory, string $className = 'AppContainer'): void
    {
        $errors = [];
        $http = null;
        $plan = null;

        try {
            $http = $this->plan($manifest);
        } catch (CompilationException $e) {
            $errors = [...$errors, ...$e->errors];
        }

        // Unbound concrete classes reachable from the roots are wired automatically; anything
        // unresolvable is reported with a path.
        try {
            $plan = $this->container->plan($manifest, $className, $http?->roots() ?? []);
        } catch (CompilationException $e) {
            $errors = [...$errors, ...$e->errors];
        }

        if ($errors !== [] || $plan === null || $http === null) {
            throw new CompilationException($errors !== [] ? $errors : ['The build produced no output.']);
        }

        $this->container->writePlan($plan, $manifest, $directory);
        $this->writeArtifacts($http, $directory);
    }

    /**
     * @return list<string>
     */
    private function middleware(ModuleManifest $manifest): array
    {
        $collector = new MiddlewareCollector();

        foreach ($manifest->modules as $class) {
            $module = new $class();

            if ($module instanceof MiddlewareProvider) {
                $module->middleware($collector);
            }
        }

        return $collector->ids();
    }
}

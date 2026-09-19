<?php

declare(strict_types=1);

namespace Trunk\Compiler\Build;

use Throwable;
use Trunk\Compiler\ArtifactWriter;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Contracts\BuildContributor;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Manifest\ModuleGraph;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Support\Directory;
use Trunk\Support\FileWriter;

/**
 * Builds a production artifact set. Every module that implements BuildContributor plans its part;
 * the container is compiled once with the union of their roots; all errors are reported together;
 * and only when everything is valid are the files written, into a fresh directory that then
 * replaces the old build (so stale artifacts of removed capabilities never linger).
 */
final readonly class BuildRunner
{
    public function __construct(
        private ArtifactWriter $container = new ArtifactWriter(),
        private FileWriter $files = new FileWriter(),
        private Directory $directories = new Directory(),
    ) {}

    /**
     * @param array<string, array<array-key, mixed>> $configuration evaluated configuration to compile into build/config.php
     *
     * @throws CompilationException
     */
    public function run(ModuleManifest $manifest, Runtime $runtime, array $configuration, string $directory): BuildReport
    {
        $context = new BuildContext($manifest, $runtime, new Configuration($configuration, $runtime->variables, false));
        $errors = new ModuleGraph()->errors($manifest->modules);
        $roots = [];
        $writers = [];
        $contributors = [];

        foreach ($manifest->modules as $class) {
            $module = new $class();

            if (!$module instanceof BuildContributor) {
                continue;
            }

            try {
                $contribution = $module->plan($context);
            } catch (CompilationException $e) {
                $errors = [...$errors, ...$e->errors];

                continue;
            }

            $contributors[] = $class;
            $roots = [...$roots, ...$contribution->containerRoots];
            $writers = [...$writers, ...$contribution->writers];
        }

        $plan = null;

        try {
            $plan = $this->container->plan($manifest, 'AppContainer', array_values(array_unique($roots)));
        } catch (CompilationException $e) {
            $errors = [...$errors, ...$e->errors];
        }

        if ($errors !== []) {
            throw new CompilationException(array_values(array_unique($errors)));
        }

        if ($plan === null) {
            throw new CompilationException(['The build produced no output.']);
        }

        $staging = $directory . '.building-' . bin2hex(random_bytes(4));

        try {
            $this->container->writePlan($plan, $manifest, $staging);
            $this->files->write($staging . '/config.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($configuration, true) . ";\n");

            foreach ($writers as $write) {
                $write($staging);
            }

            $this->directories->restrict($staging);
            $this->replace($staging, $directory);
        } catch (Throwable $e) {
            $this->directories->remove($staging);

            throw $e instanceof CompilationException ? $e : new CompilationException([$e->getMessage()]);
        }

        return new BuildReport($contributors, $plan->autoRegistered);
    }

    private function replace(string $staging, string $directory): void
    {
        $previous = null;

        if (is_dir($directory)) {
            $previous = $directory . '.old-' . bin2hex(random_bytes(4));
            rename($directory, $previous);
        }

        if (!rename($staging, $directory)) {
            if ($previous !== null) {
                rename($previous, $directory);
            }

            throw new CompilationException([\sprintf('Unable to move the new build into "%s".', $directory)]);
        }

        if ($previous !== null) {
            $this->directories->remove($previous);
        }
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tusk\Compiler;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Trunk\Support\FileWriter;
use Trunk\Tusk\Exception\TemplateBuildException;
use Trunk\Tusk\Exception\TemplateSyntaxException;
use Trunk\Tusk\TemplateName;

/**
 * Build time only. Discovers every `.tusk.php` under the view roots, compiles them, verifies that
 * all layouts and includes exist and are acyclic, and only then writes the compiled files and the
 * `views.php` manifest. Nothing is written if any template fails.
 */
final readonly class TemplateBuilder
{
    private const string SUFFIX = '.tusk.php';

    public function __construct(
        private TemplateCompiler $compiler = new TemplateCompiler(),
        private FileWriter $files = new FileWriter(),
    ) {}

    /**
     * @param list<string> $roots
     *
     * @throws TemplateBuildException
     */
    public function build(array $roots, string $buildDirectory): void
    {
        $this->write($this->plan($roots), $buildDirectory);
    }

    /**
     * Compiles and validates every template without touching the filesystem.
     *
     * @param list<string> $roots
     *
     * @return array<string, string> template name => compiled PHP
     *
     * @throws TemplateBuildException
     */
    public function plan(array $roots): array
    {
        $errors = [];
        $compiled = [];
        $graph = [];

        foreach ($this->discover($roots, $errors) as $name => $file) {
            try {
                $result = $this->compiler->compile((string) file_get_contents($file), $name);
            } catch (TemplateSyntaxException $e) {
                $errors[] = $e->getMessage();

                continue;
            }

            $compiled[$name] = $result->php;
            $graph[$name] = $result->dependencies;
        }

        foreach ($graph as $name => $dependencies) {
            foreach ($dependencies as $dependency) {
                if (!isset($graph[$dependency]) && !isset($compiled[$dependency])) {
                    $errors[] = \sprintf('Template "%s" refers to "%s", which does not exist.', $name, $dependency);
                }
            }
        }

        $errors = [...$errors, ...$this->cycles($graph)];

        if ($errors !== []) {
            throw new TemplateBuildException(array_values(array_unique($errors)));
        }

        return $compiled;
    }

    /**
     * @param array<string, string> $compiled template name => compiled PHP, from plan()
     */
    public function write(array $compiled, string $buildDirectory): void
    {
        $directory = $buildDirectory . '/views';

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new TemplateBuildException([\sprintf('Unable to create "%s".', $directory)]);
        }

        $files = [];

        foreach ($compiled as $name => $php) {
            $files[$name] = 'views/' . sha1($name) . '.php';
            $this->files->write($buildDirectory . '/' . $files[$name], $php);
        }

        $this->files->write(
            $buildDirectory . '/views.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn new \\Trunk\\Tusk\\Loader\\ViewManifest(\n    files: " . var_export($files, true) . ",\n);\n",
        );
    }

    /**
     * @param list<string>       $roots
     * @param list<string>       $errors
     *
     * @return array<string, string> template name => file path
     */
    private function discover(array $roots, array &$errors): array
    {
        $found = [];

        foreach ($roots as $root) {
            $realRoot = realpath($root);

            if ($realRoot === false) {
                $errors[] = \sprintf('View directory "%s" does not exist.', $root);

                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), self::SUFFIX)) {
                    continue;
                }

                $name = str_replace(\DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), \strlen($realRoot) + 1, -\strlen(self::SUFFIX)));

                if (!TemplateName::isValid($name)) {
                    $errors[] = \sprintf('"%s" is not a valid template file name (use letters, digits, "_" and "-").', $file->getPathname());
                } elseif (!isset($found[$name])) {
                    $found[$name] = $file->getPathname();
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @param array<string, list<string>> $graph
     *
     * @return list<string>
     */
    private function cycles(array $graph): array
    {
        $errors = [];
        $state = [];

        foreach (array_keys($graph) as $name) {
            $this->visit((string) $name, [], $graph, $state, $errors);
        }

        return $errors;
    }

    /**
     * @param list<string>                $path
     * @param array<string, list<string>> $graph
     * @param array<string, int>          $state  1 = in progress, 2 = done
     * @param list<string>                $errors
     */
    private function visit(string $name, array $path, array $graph, array &$state, array &$errors): void
    {
        if (($state[$name] ?? 0) === 2) {
            return;
        }

        if (($state[$name] ?? 0) === 1) {
            $errors[] = 'Circular template reference: ' . implode(' -> ', [...$path, $name]);

            return;
        }

        $state[$name] = 1;

        foreach ($graph[$name] ?? [] as $dependency) {
            $this->visit($dependency, [...$path, $name], $graph, $state, $errors);
        }

        $state[$name] = 2;
    }
}

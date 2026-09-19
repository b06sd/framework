<?php

declare(strict_types=1);

namespace Trunk\Console\Scaffold;

use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Project\Project;
use Trunk\Support\FileWriter;

/**
 * `make:*` code generation. Names are validated, files are never overwritten, and the destination
 * is always inside the project.
 */
final readonly class Generator
{
    public const array KINDS = ['controller', 'service', 'middleware', 'command', 'module', 'test'];

    public function __construct(
        private string $stubs = __DIR__ . '/../../resources/stubs/make',
        private FileWriter $files = new FileWriter(),
    ) {}

    public function make(Project $project, string $kind, string $name): GeneratedFile
    {
        if (preg_match('/^[A-Z][A-Za-z0-9]{0,63}$/D', $name) !== 1) {
            throw new CommandFailedException(\sprintf('"%s" is not a valid class name. Use PascalCase letters and digits only, for example "InvoiceService".', $name));
        }

        $namespace = $this->namespaceFor($project, 'autoload', 'app/', 'App');
        $vars = ['%%namespace%%' => $namespace, '%%class%%' => $name, '%%name%%' => $name];

        [$path, $stub, $hint] = match ($kind) {
            'controller' => [
                'app/Controllers/' . $name . '.php',
                \in_array('Trunk\\Mvc\\MvcModule', $project->modules, true) ? 'controller.web.stub' : 'controller.json.stub',
                \sprintf('Add a route in routes/*.php:  $routes->get(\'/path\', [\\%s\\Controllers\\%s::class, \'index\']);', $namespace, $name),
            ],
            'service' => ['app/Services/' . $name . '.php', 'service.stub', 'Inject it into a controller or another service through the constructor; Trunk wires it automatically.'],
            'middleware' => [
                'app/Middleware/' . $name . '.php',
                'middleware.stub',
                \sprintf('Register it globally by implementing MiddlewareProvider in a module and calling $middleware->add(\\%s\\Middleware\\%s::class).', $namespace, $name),
            ],
            'command' => [
                'app/Commands/' . $name . '.php',
                'command.stub',
                \sprintf('Register it in your module: $commands->add(\\%s\\Commands\\%s::class);', $namespace, $name),
            ],
            'module' => $this->module($namespace, $name, $vars),
            'test' => $this->test($project, $name, $vars),
            default => throw new CommandFailedException(\sprintf('Unknown generator "%s". Available: %s.', $kind, implode(', ', self::KINDS))),
        };

        $vars['%%commandName%%'] = 'app:' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', preg_replace('/Command$/', '', $name) ?? $name));

        $destination = $project->path($path);

        if (file_exists($destination)) {
            throw new CommandFailedException(\sprintf('%s already exists; Trunk will not overwrite it.', $path));
        }

        $this->assertInside($project, $destination);
        $contents = file_get_contents($this->stubs . '/' . $stub);

        if ($contents === false) {
            throw new CommandFailedException(\sprintf('The generator stub "%s" is missing; the trunk installation looks incomplete.', $stub));
        }

        is_dir(\dirname($destination)) || mkdir(\dirname($destination), 0o755, true);
        $this->files->write($destination, strtr($contents, $vars));

        return new GeneratedFile($path, $hint);
    }

    /**
     * @param array<string, string> $vars
     *
     * @return array{string, string, string}
     */
    private function module(string $namespace, string $name, array &$vars): array
    {
        $base = preg_replace('/Module$/', '', $name);
        $base = $base === null || $base === '' ? $name : $base;
        $vars['%%name%%'] = $base;

        return [
            'app/' . $base . '/' . $base . 'Module.php',
            'module.stub',
            \sprintf('Add \\%s\\%s\\%sModule::class to the modules list in trunk.php.', $namespace, $base, $base),
        ];
    }

    /**
     * @param array<string, string> $vars
     *
     * @return array{string, string, string}
     */
    private function test(Project $project, string $name, array &$vars): array
    {
        $class = str_ends_with($name, 'Test') ? $name : $name . 'Test';
        $vars['%%class%%'] = $class;
        $vars['%%testNamespace%%'] = $this->namespaceFor($project, 'autoload-dev', 'tests/', 'Tests');

        return ['tests/' . $class . '.php', 'test.stub', 'Run it with `trunk test`.'];
    }

    private function namespaceFor(Project $project, string $section, string $directory, string $default): string
    {
        $file = $project->path('composer.json');
        $composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $psr4 = \is_array($composer) && \is_array($composer[$section] ?? null) && \is_array($composer[$section]['psr-4'] ?? null) ? $composer[$section]['psr-4'] : [];

        foreach ($psr4 as $prefix => $path) {
            if (\is_string($path) && rtrim($path, '/') === rtrim($directory, '/')) {
                return rtrim((string) $prefix, '\\');
            }
        }

        return $default;
    }

    private function assertInside(Project $project, string $destination): void
    {
        $base = realpath($project->basePath);
        $parent = \dirname($destination);

        while (!is_dir($parent) && $parent !== \dirname($parent)) {
            $parent = \dirname($parent);
        }

        $real = realpath($parent);

        if ($base === false || $real === false || ($real !== $base && !str_starts_with($real, $base . \DIRECTORY_SEPARATOR))) {
            throw new CommandFailedException('Refusing to write outside the project directory.');
        }
    }
}

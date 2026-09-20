<?php

declare(strict_types=1);

namespace Trunk\Console\Scaffold;

use ParseError;
use PhpToken;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Capability\CapabilityPublisher;
use Trunk\Foundation\Capability\ComposerRequirements;
use Trunk\Foundation\Project\Project;
use Trunk\Support\FileWriter;

/**
 * Creates a new project directory from a Profile. Everything is rendered and validated in memory
 * first (PHP is parsed, JSON decoded); nothing is written unless all of it is valid, and existing
 * files are never overwritten unless `$force` is set.
 */
final readonly class ProjectScaffolder
{
    public function __construct(
        private string $stubs = __DIR__ . '/../../resources/stubs',
        private FileWriter $files = new FileWriter(),
        private CapabilityPublisher $publisher = new CapabilityPublisher(),
    ) {}

    public function isValidName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $name) === 1;
    }

    /**
     * @return list<string> paths created, relative to the project directory
     */
    public function create(string $name, Profile $profile, string $target, ?string $repository = null, bool $force = false): array
    {
        if (!$this->isValidName($name)) {
            throw new CommandFailedException('The project name must be lowercase letters, digits, "-" or "_" (for example "customer-api").');
        }

        if (is_dir($target) && (glob($target . '/*') !== [] || glob($target . '/.[!.]*') !== []) && !$force) {
            throw new CommandFailedException(\sprintf('"%s" already exists and is not empty. Choose another name or pass --force.', $target));
        }

        $repositoryRoot = null;

        if ($repository !== null) {
            $repositoryRoot = realpath($repository);

            if ($repositoryRoot === false || !is_file($repositoryRoot . '/composer.json')) {
                throw new CommandFailedException(\sprintf('--repository must point to a directory containing composer.json, "%s" does not.', $repository));
            }
        }

        $files = $this->render($name, $profile, $repositoryRoot);
        $this->validate($files);

        foreach ($files as $path => $contents) {
            $destination = $target . '/' . $path;
            is_dir(\dirname($destination)) || mkdir(\dirname($destination), 0o755, true);
            $this->files->write($destination, $contents, $path === '.env' ? 0o640 : null);
        }

        // Each capability adds what it needs (config files, .env settings, directories).
        $project = new Project($target, $name, $profile->value, []);
        $created = array_keys($files);

        foreach ($profile->plan() as $capability) {
            $this->publisher->publish($project, $capability);

            foreach (array_keys($capability->config) as $config) {
                $created[] = 'config/' . $config . '.php';
            }
        }

        return $created;
    }

    /**
     * The extra `require` lines for what the profile's capabilities need beyond the core (for
     * example the PSR HTTP interfaces), so a fresh `composer install` yields a working project.
     */
    private function requirements(Profile $profile): string
    {
        $lines = '';

        foreach (new ComposerRequirements()->for($profile->plan()) as $package => $constraint) {
            $lines .= ",\n        " . json_encode($package, \JSON_THROW_ON_ERROR) . ': ' . json_encode($constraint, \JSON_THROW_ON_ERROR);
        }

        return $lines;
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private function render(string $name, Profile $profile, ?string $repository): array
    {
        $title = ucwords(str_replace(['-', '_'], ' ', $name));
        $modules = $profile->modules();
        $vars = [
            '%%name%%' => $name,
            '%%title%%' => $title,
            '%%type%%' => $profile->value,
            '%%requires%%' => $this->requirements($profile),
            // A local checkout is a dev version (branch alias 0.1.x-dev), so it needs `dev` stability and any version;
            // the published package is resolved as a normal stable release.
            '%%framework_version%%' => $repository === null ? '^0.1' : '*',
            '%%stability%%' => $repository === null ? 'stable' : 'dev',
            '%%repositories%%' => $repository === null ? '' : ",\n    \"repositories\": [\n        {\"type\": \"path\", \"url\": " . json_encode($repository, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . ", \"options\": {\"symlink\": true}}\n    ]",
            '%%modules_md%%' => implode("\n", array_map(static fn(string $m): string => '- `' . $m . '`', $modules)),
            '%%start%%' => $profile->hasHttp() ? 'trunk serve   # http://127.0.0.1:8006' : ($profile->hasConsole() ? 'trunk list    # your commands are listed here' : ''),
            '%%layout%%' => $this->layoutDoc($profile),
            '%%extra%%' => $profile === Profile::SelfContained ? "\n## Growing this application\n\nThe cache capability is already enabled (inject `Psr\\SimpleCache\\CacheInterface`). Database,\nauthentication and jobs are separate capabilities that will be added with `trunk package:install`\nand listed in `trunk.php` when their packages exist. Run `trunk package:list` to see what is available.\n" : ($profile === Profile::Worker ? "\n## The worker\n\nCreate the queue tables once: `trunk queue:table && trunk migrate`. Then `trunk welcome:enqueue` queues two example jobs (`app/Jobs/SendWelcome.php`) and `trunk queue:work --stop-when-empty` runs them, each in its own container scope. Run `trunk queue:work` under a process supervisor in production and let it restart (`queue.worker.*` in config/queue.php sets its limits).\n" : ''),
            '%%routes%%' => $this->routeLoading($profile),
            '%%port_line%%' => $profile->hasHttp() ? 'APP_PORT=8006' : '',
        ];

        $files = [
            'composer.json' => $this->stub('common/composer.json.stub', $vars),
            'trunk.php' => $this->manifest($name, $profile),
            '.gitignore' => $this->stub('common/gitignore.stub', $vars),
            '.env.example' => $this->stub('common/env.example.stub', $vars),
            '.env' => $this->stub('common/env.example.stub', $vars),
            'README.md' => $this->stub('common/README.md.stub', $vars),
            'config/app.php' => $this->stub('common/config-app.php.stub', $vars),
            'phpunit.xml' => $this->stub('common/phpunit.xml.stub', $vars),
            'tests/AppModuleTest.php' => $this->stub('common/AppModuleTest.php.stub', $vars),
            'storage/.gitkeep' => '',
        ];

        if ($profile->hasHttp()) {
            $files['public/index.php'] = $this->stub('http/index.php.stub', $vars);
            $files['app/AppModule.php'] = $this->stub('http/AppModule.php.stub', $vars);
        }

        if ($profile->hasApiRoutes()) {
            $files['routes/api.php'] = $this->stub('api/routes-api.php.stub', $vars);
            $files['app/Controllers/CustomerController.php'] = $this->stub('api/CustomerController.php.stub', $vars);
            $files['app/Services/CustomerService.php'] = $this->stub('api/CustomerService.php.stub', $vars);
            $files['app/DTOs/Customer.php'] = $this->stub('api/Customer.php.stub', $vars);
            $files['app/Repositories/.gitkeep'] = '';
        }

        if ($profile->hasWebRoutes()) {
            $files['routes/web.php'] = $this->stub('web/routes-web.php.stub', $vars);
            $files['app/Controllers/HomeController.php'] = $this->stub('web/HomeController.php.stub', $vars);
            $files['resources/views/layouts/app.tusk.php'] = $this->stub('web/layout-app.tusk.php.stub', $vars);
            $files['resources/views/home.tusk.php'] = $this->stub('web/home.tusk.php.stub', $vars);
            $files['public/styles.css'] = $this->stub('web/styles.css.stub', $vars);
            $files['public/script.js'] = $this->stub('web/script.js.stub', $vars);
            $files['resources/views/errors/404.tusk.php'] = $this->stub('web/error-404.tusk.php.stub', $vars);
            $files['resources/views/errors/error.tusk.php'] = $this->stub('web/error.tusk.php.stub', $vars);
        }

        if ($profile->hasConsole()) {
            $files['app/AppModule.php'] = $this->stub($profile === Profile::Worker ? 'worker/AppModule.php.stub' : 'cli/AppModule.php.stub', $vars);
            $files['app/Commands/ImportCustomersCommand.php'] = $this->stub('cli/ImportCustomersCommand.php.stub', $vars);
            $files['app/Services/CustomerImporter.php'] = $this->stub('cli/CustomerImporter.php.stub', $vars);
        }

        if ($profile === Profile::Worker) {
            $files['app/Commands/EnqueueWelcomeCommand.php'] = $this->stub('worker/EnqueueWelcomeCommand.php.stub', $vars);
            $files['app/Jobs/SendWelcome.php'] = $this->stub('worker/SendWelcome.php.stub', $vars);
        }

        return $files;
    }

    private function manifest(string $name, Profile $profile): string
    {
        $modules = implode("\n", array_map(static fn(string $m): string => '        \\' . $m . '::class,', $profile->modules()));

        return "<?php\n\ndeclare(strict_types=1);\n\n// The module list is this application's capability list.\nreturn [\n    'name' => '" . $name . "',\n    'type' => '" . $profile->value . "',\n    'modules' => [\n" . $modules . "\n    ],\n];\n";
    }

    private function layoutDoc(Profile $profile): string
    {
        $lines = [];

        if ($profile->hasHttp()) {
            $lines[] = 'public/      the web entry point (public/index.php) and static files (styles.css, script.js)';
            $lines[] = 'routes/      route definitions';
        }

        if ($profile->hasWebRoutes()) {
            $lines[] = 'resources/   Tusk views (*.tusk.php)';
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    private function routeLoading(Profile $profile): string
    {
        $files = array_filter([$profile->hasWebRoutes() ? 'web' : null, $profile->hasApiRoutes() ? 'api' : null]);

        return implode("\n", array_map(static fn(string $f): string => "        (require __DIR__ . '/../routes/" . $f . ".php')(\$routes);", $files));
    }

    /**
     * @param array<string, string> $vars
     */
    private function stub(string $path, array $vars): string
    {
        $contents = file_get_contents($this->stubs . '/' . $path);

        if ($contents === false) {
            throw new CommandFailedException(\sprintf('The scaffolding stub "%s" is missing; the trunk installation looks incomplete.', $path));
        }

        return strtr($contents, $vars);
    }

    /**
     * @param array<string, string> $files
     */
    private function validate(array $files): void
    {
        foreach ($files as $path => $contents) {
            if (str_ends_with($path, '.php') && !str_ends_with($path, '.tusk.php')) {
                try {
                    PhpToken::tokenize($contents, \TOKEN_PARSE);
                } catch (ParseError $e) {
                    throw new CommandFailedException(\sprintf('Internal error: the generated file %s is not valid PHP (%s). Please report this.', $path, $e->getMessage()));
                }
            }

            if (str_ends_with($path, '.json')) {
                json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
            }
        }
    }
}

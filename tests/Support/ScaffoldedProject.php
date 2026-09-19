<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use RuntimeException;

/**
 * A project created by the real `trunk new`. By default it gets a stand-in vendor/autoload.php (the
 * repository's autoloader plus PSR-4 for App\) so it runs without `composer install`; that stand-in
 * has every dev dependency of the repository, so it cannot prove what a real install provides. With
 * `$realInstall` the project uses a Composer path repository and a real `composer install`, and
 * runs through its own vendor/bin/trunk.
 */
final class ScaffoldedProject
{
    public readonly string $base;

    public readonly string $directory;

    private readonly string $repository;

    private readonly Cli $cli;

    public function __construct(string $name, string $type, private readonly bool $realInstall = false)
    {
        $this->cli = new Cli();
        $this->repository = \dirname(__DIR__, 2);
        $this->base = sys_get_temp_dir() . '/trunk-e2e-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0o755, true);
        $this->directory = $this->base . '/' . $name;

        [$code, , $err] = $this->cli->run([\PHP_BINARY, $this->repository . '/bin/trunk', 'new', $name, '--type=' . $type, ...($realInstall ? ['--repository=' . $this->repository] : [])], $this->base);

        if ($code !== 0) {
            throw new RuntimeException('trunk new failed: ' . $err);
        }

        if ($realInstall) {
            [$installCode, $installOut, $installErr] = $this->cli->run(['composer', 'install', '--no-dev', '--no-interaction', '--no-progress'], $this->directory);

            if ($installCode !== 0) {
                throw new RuntimeException('composer install failed: ' . $installOut . $installErr);
            }
        } else {
            $this->standInVendor();
        }

        file_put_contents($this->base . '/request.php', "<?php\n[\$script, \$root, \$method, \$uri] = \$argv;\n\$_SERVER['REQUEST_METHOD'] = \$method;\n\$_SERVER['REQUEST_URI'] = \$uri;\n\$_SERVER['HTTP_HOST'] = 'app.test';\n\$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';\nif ((string) getenv('TRUNK_ACCEPT') !== '') { \$_SERVER['HTTP_ACCEPT'] = getenv('TRUNK_ACCEPT'); }\nif ((string) getenv('TRUNK_AUTHORIZATION') !== '') { \$_SERVER['HTTP_AUTHORIZATION'] = getenv('TRUNK_AUTHORIZATION'); }\nrequire \$root . '/public/index.php';\n");
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     *
     * @return array{int, string, string}
     */
    public function trunk(array $arguments, ?string $cwd = null, array $environment = []): array
    {
        $entry = $this->realInstall ? $this->directory . '/vendor/bin/trunk' : $this->repository . '/bin/trunk';

        return $this->cli->run([\PHP_BINARY, $entry, ...$arguments], $cwd ?? $this->directory, $environment);
    }

    /**
     * Simulates one HTTP request through the generated public/index.php.
     *
     * @return array{int, string, string} exit code, body, stderr
     */
    public function request(string $method, string $uri, ?string $environment, bool $debug = false, ?string $accept = null, ?string $authorization = null): array
    {
        return $this->cli->run([\PHP_BINARY, $this->base . '/request.php', $this->directory, $method, $uri], $this->directory, ($environment === null ? [] : ['APP_ENV' => $environment]) + ($debug ? ['APP_DEBUG' => '1'] : []) + ($accept === null ? [] : ['TRUNK_ACCEPT' => $accept]) + ($authorization === null ? [] : ['TRUNK_AUTHORIZATION' => $authorization]));
    }

    public function cleanUp(): void
    {
        new \Trunk\Support\Directory()->remove($this->base);
    }

    private function standInVendor(): void
    {
        mkdir($this->directory . '/vendor/composer', 0o755, true);
        // The stand-in vendor directory has exactly what the repository's own vendor directory has.
        copy($this->repository . '/vendor/composer/installed.json', $this->directory . '/vendor/composer/installed.json');
        file_put_contents($this->directory . '/vendor/autoload.php', "<?php\nrequire_once " . var_export($this->repository . '/vendor/autoload.php', true) . ";\nspl_autoload_register(static function (string \$c): void {\n    if (str_starts_with(\$c, 'App\\\\')) {\n        \$f = __DIR__ . '/../app/' . str_replace('\\\\', '/', substr(\$c, 4)) . '.php';\n        if (is_file(\$f)) {\n            require \$f;\n        }\n    }\n});\n");
    }
}

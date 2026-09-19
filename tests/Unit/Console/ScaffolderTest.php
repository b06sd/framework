<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Console;

use ParseError;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Console\Scaffold\Profile;
use Trunk\Console\Scaffold\ProjectScaffolder;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Contracts\Console\Exception\UsageException;
use Trunk\Support\Directory;

final class ScaffolderTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/trunk-scaffold-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->base);
    }

    /**
     * @return iterable<string, array{Profile}>
     */
    public static function profiles(): iterable
    {
        foreach (Profile::cases() as $profile) {
            yield $profile->value => [$profile];
        }
    }

    #[DataProvider('profiles')]
    public function test_every_profile_creates_valid_php_json_and_a_module_list(Profile $profile): void
    {
        // Arrange
        $scaffolder = new ProjectScaffolder();
        $target = $this->base . '/demo-app';

        // Act
        $created = $scaffolder->create('demo-app', $profile, $target);

        // Assert
        self::assertContains('trunk.php', $created);
        self::assertContains('composer.json', $created);
        self::assertContains('app/AppModule.php', $created);
        self::assertFileExists($target . '/storage/.gitkeep');

        foreach ($created as $path) {
            $contents = (string) file_get_contents($target . '/' . $path);
            self::assertStringNotContainsString('%%', $contents, $path);

            if (str_ends_with($path, '.php') && !str_ends_with($path, '.tusk.php')) {
                try {
                    PhpToken::tokenize($contents, \TOKEN_PARSE);
                } catch (ParseError $e) {
                    self::fail($path . ': ' . $e->getMessage());
                }
            }
        }

        self::assertStringContainsString('"name": "app/demo-app"', (string) file_get_contents($target . '/composer.json'));
        $manifest = (string) file_get_contents($target . '/trunk.php');

        foreach ($profile->modules() as $module) {
            self::assertStringContainsString('\\' . $module . '::class', $manifest);
        }
    }

    public function test_profiles_differ_by_capability_not_by_framework(): void
    {
        // Arrange
        $scaffolder = new ProjectScaffolder();

        // Act
        $api = $scaffolder->create('a', Profile::Api, $this->base . '/a');
        $web = $scaffolder->create('w', Profile::Web, $this->base . '/w');
        $cli = $scaffolder->create('c', Profile::Cli, $this->base . '/c');
        $worker = $scaffolder->create('k', Profile::Worker, $this->base . '/k');
        $mono = $scaffolder->create('m', Profile::SelfContained, $this->base . '/m');

        // Assert
        self::assertContains('routes/api.php', $api);
        self::assertNotContains('config/views.php', $api);
        self::assertContains('resources/views/home.tusk.php', $web);
        self::assertNotContains('routes/api.php', $web);
        self::assertNotContains('public/index.php', $cli);
        self::assertContains('app/Commands/ImportCustomersCommand.php', $cli);
        self::assertContains('app/Jobs/SendWelcome.php', $worker);
        self::assertContains('config/queue.php', $worker);
        self::assertNotContains('app/Commands/WorkCommand.php', $worker);
        self::assertContains('routes/web.php', $mono);
        self::assertContains('routes/api.php', $mono);
    }

    public function test_each_profile_requires_exactly_the_packages_its_capabilities_need(): void
    {
        // Arrange
        $scaffolder = new ProjectScaffolder();
        $expected = [
            'api' => ['psr/http-factory', 'psr/http-message', 'psr/http-server-handler', 'psr/http-server-middleware'],
            'cli' => [],
            'worker' => ['ext-pdo'],
        ];

        foreach ($expected as $type => $packages) {
            // Act
            $scaffolder->create('p-' . $type, Profile::from($type), $this->base . '/p-' . $type);
            $composer = json_decode((string) file_get_contents($this->base . '/p-' . $type . '/composer.json'), true, 16, \JSON_THROW_ON_ERROR);
            $require = \is_array($composer) && \is_array($composer['require'] ?? null) ? $composer['require'] : [];
            $extra = array_values(array_diff(array_keys($require), ['php', 'trunk/framework']));
            sort($extra);

            // Assert
            self::assertSame($packages, $extra, $type);
        }
    }

    public function test_a_project_without_a_local_checkout_asks_for_the_published_release_at_stable_stability(): void
    {
        // Arrange
        $scaffolder = new ProjectScaffolder();

        // Act
        $scaffolder->create('published', Profile::Api, $this->base . '/published');
        $scaffolder->create('linked2', Profile::Api, $this->base . '/linked2', \dirname(__DIR__, 3));
        $published = json_decode((string) file_get_contents($this->base . '/published/composer.json'), true, 16, \JSON_THROW_ON_ERROR);
        $linked = json_decode((string) file_get_contents($this->base . '/linked2/composer.json'), true, 16, \JSON_THROW_ON_ERROR);

        // Assert
        self::assertIsArray($published);
        self::assertIsArray($published['require']);
        self::assertSame('^0.1', $published['require']['trunk/framework']);
        self::assertSame('stable', $published['minimum-stability']);
        self::assertArrayNotHasKey('repositories', $published);
        self::assertIsArray($linked);
        self::assertIsArray($linked['require']);
        self::assertSame('*', $linked['require']['trunk/framework'], 'a local checkout is a dev version, so any version');
        self::assertSame('dev', $linked['minimum-stability']);
    }

    public function test_a_local_framework_checkout_can_be_used_as_a_composer_path_repository(): void
    {
        // Arrange
        $scaffolder = new ProjectScaffolder();

        // Act
        $scaffolder->create('linked', Profile::Api, $this->base . '/linked', \dirname(__DIR__, 3));
        $composer = (string) file_get_contents($this->base . '/linked/composer.json');

        // Assert
        self::assertStringContainsString('"repositories"', $composer);
        self::assertStringContainsString('"type": "path"', $composer);
        self::assertStringContainsString((string) json_encode(\dirname(__DIR__, 3), \JSON_UNESCAPED_SLASHES), $composer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'uppercase' => ['MyApp'];
        yield 'traversal' => ['../escape'];
        yield 'slash' => ['a/b'];
        yield 'space' => ['my app'];
        yield 'shell' => ['x;rm'];
        yield 'empty' => [''];
        yield 'dot' => ['.hidden'];
    }

    #[DataProvider('badNames')]
    public function test_project_names_must_be_plain_lowercase_identifiers(string $name): void
    {
        // Arrange
        $scaffolder = new ProjectScaffolder();

        // Act & Assert
        $this->expectException(CommandFailedException::class);
        $scaffolder->create($name, Profile::Api, $this->base . '/target');
    }

    public function test_a_non_empty_directory_is_never_overwritten_without_force(): void
    {
        // Arrange
        $scaffolder = new ProjectScaffolder();
        mkdir($this->base . '/taken');
        file_put_contents($this->base . '/taken/precious.txt', 'keep me');

        // Act & Assert
        try {
            $scaffolder->create('taken', Profile::Api, $this->base . '/taken');
            self::fail('Expected a CommandFailedException.');
        } catch (CommandFailedException $e) {
            self::assertStringContainsString('--force', $e->getMessage());
            self::assertSame('keep me', file_get_contents($this->base . '/taken/precious.txt'));
        }
    }

    public function test_unknown_types_and_bad_repositories_are_explained(): void
    {
        // Arrange
        $scaffolder = new ProjectScaffolder();

        // Act & Assert
        try {
            Profile::fromName('microservice');
            self::fail('Expected a UsageException.');
        } catch (UsageException $e) {
            self::assertStringContainsString('api, web, self-contained, cli, worker', $e->getMessage());
        }

        $this->expectException(CommandFailedException::class);
        $scaffolder->create('demo', Profile::Api, $this->base . '/demo', '/definitely/not/here');
    }
}

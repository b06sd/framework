<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;
use Trunk\Application\Application;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Exception\ProjectException;
use Trunk\Foundation\Project\ApplicationFactory;
use Trunk\Foundation\Project\ConfigurationLoader;
use Trunk\Foundation\Project\Project;
use Trunk\Foundation\Project\ProjectLoader;
use Trunk\Foundation\Runtime;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Modules\AlphaModule;

final class ProjectTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/trunk-project-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/config', 0o755, true);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->base);
    }

    public function test_trunk_php_is_loaded_into_a_project(): void
    {
        // Arrange
        $this->manifest("['name' => 'crm', 'type' => 'web', 'modules' => [\\Trunk\\Tests\\Fixtures\\Modules\\AlphaModule::class]]");

        // Act
        $project = new ProjectLoader()->load($this->base);

        // Assert
        self::assertSame('crm', $project->name);
        self::assertSame('web', $project->type);
        self::assertSame([AlphaModule::class], $project->modules);
        self::assertSame($this->base . '/build', $project->buildDirectory());
    }

    public function test_the_project_root_is_found_from_a_subdirectory(): void
    {
        // Arrange
        $this->manifest("['name' => 'crm', 'type' => 'web', 'modules' => []]");
        mkdir($this->base . '/app/Deep', 0o755, true);
        $loader = new ProjectLoader();

        // Act
        $found = $loader->locate($this->base . '/app/Deep');

        // Assert
        self::assertSame(realpath($this->base), $found);
        self::assertNull($loader->locate(sys_get_temp_dir() . '/definitely-not-a-project-' . bin2hex(random_bytes(3))));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidManifests(): iterable
    {
        yield 'not an array' => ["'oops'", 'must return an array'];
        yield 'bad name' => ["['name' => 'My App', 'type' => 'web', 'modules' => []]", '"name"'];
        yield 'bad type' => ["['name' => 'a', 'type' => 'Web!', 'modules' => []]", '"type"'];
        yield 'modules not a list' => ["['name' => 'a', 'type' => 'web', 'modules' => 'x']", '"modules"'];
        yield 'unknown module class' => ["['name' => 'a', 'type' => 'web', 'modules' => ['Nope\\\\Missing']]", 'composer dump-autoload'];
        yield 'not a module' => ["['name' => 'a', 'type' => 'web', 'modules' => [\\stdClass::class]]", 'not a class implementing'];
    }

    /**
     * @dataProvider invalidManifests
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidManifests')]
    public function test_a_malformed_trunk_php_is_explained(string $php, string $expected): void
    {
        // Arrange
        $this->manifest($php);

        // Act & Assert
        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage($expected);
        new ProjectLoader()->load($this->base);
    }

    public function test_a_missing_trunk_php_says_how_to_create_a_project(): void
    {
        // Arrange
        $loader = new ProjectLoader();

        // Act & Assert
        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage('trunk new');
        $loader->load($this->base);
    }

    public function test_config_files_may_be_arrays_or_closures_of_the_runtime(): void
    {
        // Arrange
        file_put_contents($this->base . '/config/app.php', "<?php\nreturn ['name' => 'X'];\n");
        file_put_contents($this->base . '/config/views.php', "<?php\nreturn static fn (\\Trunk\\Foundation\\Runtime \$r): array => ['mode' => \$r->environment === \\Trunk\\Foundation\\Environment::Production ? 'compiled' : 'development', 'root' => \$r->basePath];\n");
        $project = new Project($this->base, 'x', 'web', []);
        $loader = new ConfigurationLoader();

        // Act
        $production = $loader->evaluate($project, new Runtime(Environment::Production, false, $this->base));
        $local = $loader->fromDirectory($project, new Runtime(Environment::Local, true, $this->base));

        // Assert
        self::assertSame(['app' => ['name' => 'X'], 'views' => ['mode' => 'compiled', 'root' => $this->base]], $production);
        self::assertSame('development', $local->string('views.mode'));
    }

    public function test_configuration_must_be_plain_data_so_it_can_be_compiled(): void
    {
        // Arrange
        file_put_contents($this->base . '/config/bad.php', "<?php\nreturn ['when' => new \\DateTimeImmutable()];\n");
        $project = new Project($this->base, 'x', 'web', []);

        // Act & Assert
        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage('DateTimeImmutable');
        new ConfigurationLoader()->evaluate($project, new Runtime(Environment::Local, false, $this->base));
    }

    public function test_production_refuses_to_start_without_a_build_and_development_does_not_need_one(): void
    {
        // Arrange
        $project = new Project($this->base, 'x', 'web', [AlphaModule::class]);
        $factory = new ApplicationFactory();

        // Act
        $development = $factory->create($project, new Runtime(Environment::Local, true, $this->base));

        // Assert
        self::assertInstanceOf(Application::class, $development);
        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage('trunk build');
        $factory->create($project, new Runtime(Environment::Production, false, $this->base));
    }

    public function test_the_runtime_comes_from_explicit_environment_values_and_defaults_to_production(): void
    {
        // Arrange
        $project = new Project($this->base, 'x', 'web', []);
        $factory = new ApplicationFactory();

        // Act
        $unset = $factory->runtime($project, ['APP_DEBUG' => '1']);
        $local = $factory->runtime($project, ['APP_ENV' => 'local', 'APP_DEBUG' => 'true', 'APP_NAME' => 'Demo']);
        $junk = $factory->runtime($project, ['APP_ENV' => 'staging', 'APP_DEBUG' => '1']);

        // Assert
        self::assertSame(Environment::Production, $unset->environment);
        self::assertFalse($unset->debug);
        self::assertSame(Environment::Local, $local->environment);
        self::assertTrue($local->debug);
        self::assertSame('Demo', $local->variable('APP_NAME'));
        self::assertSame('fallback', $local->variable('NOPE', 'fallback'));
        self::assertSame(Environment::Production, $junk->environment);
    }

    private function manifest(string $php): void
    {
        file_put_contents($this->base . '/trunk.php', "<?php\nreturn " . $php . ";\n");
    }
}

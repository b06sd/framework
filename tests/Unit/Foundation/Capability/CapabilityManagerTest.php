<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation\Capability;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Capability\CapabilityManager;
use Trunk\Foundation\Exception\CapabilityException;
use Trunk\Tests\Support\CapabilityWorkspace;

final class CapabilityManagerTest extends TestCase
{
    private CapabilityWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new CapabilityWorkspace();
        file_put_contents($this->workspace->base . '/.env', "APP_ENV=local\n");
        file_put_contents($this->workspace->base . '/.env.example', "APP_ENV=local\n");
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanUp();
    }

    public function test_enabling_a_capability_adds_its_module_before_the_application_module_and_publishes_its_files(): void
    {
        // Arrange
        $manager = new CapabilityManager();

        // Act
        $changes = $manager->enable($this->workspace->project(), 'cache');
        $project = $this->workspace->project();

        // Assert
        self::assertSame(['Trunk\\Http\\HttpModule', 'Trunk\\Cache\\CacheModule', 'Trunk\\Tests\\Fixtures\\Modules\\AlphaModule'], $project->modules);
        self::assertFileExists($this->workspace->base . '/config/cache.php');
        self::assertStringContainsString("APP_ENV=local\n\n# Cache\nCACHE_DRIVER=file\n", (string) file_get_contents($this->workspace->base . '/.env'));
        self::assertStringContainsString('CACHE_DRIVER=file', (string) file_get_contents($this->workspace->base . '/.env.example'));
        self::assertContains('Enabled Cache.', $changes);
        self::assertContains('Created config/cache.php.', $changes);
    }

    public function test_requirements_are_enabled_first_and_directories_are_created(): void
    {
        // Arrange
        $this->workspace->manifest(['Trunk\\Tests\\Fixtures\\Modules\\AlphaModule']);
        $manager = new CapabilityManager();

        // Act
        $changes = $manager->enable($this->workspace->project(), 'mvc');

        // Assert
        self::assertSame(['Trunk\\Http\\HttpModule', 'Trunk\\Tusk\\TuskModule', 'Trunk\\Mvc\\MvcModule', 'Trunk\\Tests\\Fixtures\\Modules\\AlphaModule'], $this->workspace->project()->modules);
        self::assertContains('Enabled HTTP (required).', $changes);
        self::assertContains('Enabled Tusk (required).', $changes);
        self::assertContains('Enabled MVC.', $changes);
        self::assertFileExists($this->workspace->base . '/config/views.php');
        self::assertFileExists($this->workspace->base . '/resources/views/.gitkeep');
    }

    public function test_enabling_twice_changes_nothing_and_existing_files_are_never_overwritten(): void
    {
        // Arrange
        $manager = new CapabilityManager();
        file_put_contents($this->workspace->base . '/config/cache.php', "<?php\nreturn ['mine' => true];\n");
        $manager->enable($this->workspace->project(), 'cache');
        $manifest = (string) file_get_contents($this->workspace->base . '/trunk.php');
        $env = (string) file_get_contents($this->workspace->base . '/.env');

        // Act
        $again = $manager->enable($this->workspace->project(), 'cache');

        // Assert
        self::assertSame(['"cache" is already enabled.'], $again);
        self::assertSame($manifest, file_get_contents($this->workspace->base . '/trunk.php'));
        self::assertSame($env, file_get_contents($this->workspace->base . '/.env'));
        self::assertSame("<?php\nreturn ['mine' => true];\n", file_get_contents($this->workspace->base . '/config/cache.php'));
    }

    public function test_settings_already_present_in_env_are_not_touched_or_duplicated(): void
    {
        // Arrange
        file_put_contents($this->workspace->base . '/.env', "CACHE_DRIVER=array\n");

        // Act
        new CapabilityManager()->enable($this->workspace->project(), 'cache');

        // Assert
        self::assertSame("CACHE_DRIVER=array\n", file_get_contents($this->workspace->base . '/.env'));
    }

    public function test_a_capability_that_others_need_cannot_be_removed(): void
    {
        // Arrange
        $manager = new CapabilityManager();
        $manager->enable($this->workspace->project(), 'mvc');

        // Act & Assert
        try {
            $manager->disable($this->workspace->project(), 'tusk');
            self::fail('Expected a CapabilityException.');
        } catch (CapabilityException $e) {
            self::assertStringContainsString('"mvc" requires it', $e->getMessage());
            self::assertStringContainsString('trunk package:remove mvc', $e->getMessage());
            self::assertContains('Trunk\\Tusk\\TuskModule', $this->workspace->project()->modules);
        }
    }

    public function test_disabling_removes_only_that_capabilitys_modules_and_leaves_config_alone(): void
    {
        // Arrange
        $manager = new CapabilityManager();
        $manager->enable($this->workspace->project(), 'cache');

        // Act
        $changes = $manager->disable($this->workspace->project(), 'cache');

        // Assert
        self::assertSame(['Trunk\\Http\\HttpModule', 'Trunk\\Tests\\Fixtures\\Modules\\AlphaModule'], $this->workspace->project()->modules);
        self::assertFileExists($this->workspace->base . '/config/cache.php');
        self::assertContains('Disabled Cache.', $changes);
        self::assertSame(['"cache" is not enabled.'], $manager->disable($this->workspace->project(), 'cache'));
    }

    public function test_unknown_capabilities_are_explained(): void
    {
        // Arrange
        $manager = new CapabilityManager();

        // Act & Assert
        $this->expectException(CapabilityException::class);
        $this->expectExceptionMessage('trunk package:list');
        $manager->enable($this->workspace->project(), 'teleporter');
    }

    public function test_an_uneditable_trunk_php_gives_the_exact_lines_to_add_by_hand(): void
    {
        // Arrange
        file_put_contents($this->workspace->base . '/trunk.php', "<?php\nreturn ['name' => 'demo', 'type' => 'web', 'modules' => [\\Trunk\\Http\\HttpModule::class, ...[\\Trunk\\Tests\\Fixtures\\Modules\\AlphaModule::class]]];\n");
        $original = (string) file_get_contents($this->workspace->base . '/trunk.php');

        // Act
        try {
            new CapabilityManager()->enable($this->workspace->project(), 'cache');
            self::fail('Expected a CapabilityException.');
        } catch (CapabilityException $e) {
            // Assert
            self::assertStringContainsString('Could not edit trunk.php automatically', $e->getMessage());
            self::assertStringContainsString('\\Trunk\\Cache\\CacheModule::class,', $e->getMessage());
            self::assertSame($original, file_get_contents($this->workspace->base . '/trunk.php'));
        }
    }

    public function test_an_external_package_capability_can_be_enabled_from_its_metadata(): void
    {
        // Arrange
        $this->workspace->installPackage('acme/payments', [
            'id' => 'payments',
            'name' => 'Payments',
            'modules' => ['Trunk\\Tests\\Fixtures\\Modules\\BetaModule'],
            'requires' => ['http'],
            'config' => ['payments' => 'resources/config/payments.php'],
            'env' => ['PAYMENTS_KEY' => 'change me'],
            'directories' => ['storage/payments'],
        ], ['resources/config/payments.php' => "<?php\nreturn ['key' => 'x'];\n"]);

        // Act
        $changes = new CapabilityManager()->enable($this->workspace->project(), 'payments');

        // Assert
        self::assertContains('Trunk\\Tests\\Fixtures\\Modules\\BetaModule', $this->workspace->project()->modules);
        self::assertSame("<?php\nreturn ['key' => 'x'];\n", file_get_contents($this->workspace->base . '/config/payments.php'));
        self::assertStringContainsString('PAYMENTS_KEY="change me"', (string) file_get_contents($this->workspace->base . '/.env'));
        self::assertDirectoryExists($this->workspace->base . '/storage/payments');
        self::assertContains('Enabled Payments.', $changes);
    }

    public function test_enabling_the_second_of_two_integrating_capabilities_adds_the_integration_module(): void
    {
        // Arrange
        $manager = new CapabilityManager();
        $integration = 'Trunk\\Cache\\Console\\CacheConsoleModule';

        // Act
        $manager->enable($this->workspace->project(), 'cache');
        $afterCache = $this->workspace->project()->modules;
        $changes = $manager->enable($this->workspace->project(), 'console');
        $modules = $this->workspace->project()->modules;

        // Assert
        self::assertNotContains($integration, $afterCache);
        self::assertContains('Added integration module ' . $integration . '.', $changes);
        self::assertSame(['Trunk\\Http\\HttpModule', 'Trunk\\Cache\\CacheModule', 'Trunk\\Console\\ConsoleModule', $integration, 'Trunk\\Tests\\Fixtures\\Modules\\AlphaModule'], $modules);
    }

    public function test_the_order_of_enabling_does_not_matter(): void
    {
        // Arrange
        $manager = new CapabilityManager();

        // Act
        $manager->enable($this->workspace->project(), 'console');
        $manager->enable($this->workspace->project(), 'cache');

        // Assert
        self::assertContains('Trunk\\Cache\\Console\\CacheConsoleModule', $this->workspace->project()->modules);
    }

    public function test_disabling_either_side_removes_the_integration_module(): void
    {
        // Arrange
        $manager = new CapabilityManager();
        $manager->enable($this->workspace->project(), 'cache');
        $manager->enable($this->workspace->project(), 'console');
        $integration = 'Trunk\\Cache\\Console\\CacheConsoleModule';

        // Act
        $changes = $manager->disable($this->workspace->project(), 'console');
        $afterConsole = $this->workspace->project()->modules;
        $manager->enable($this->workspace->project(), 'console');
        $manager->disable($this->workspace->project(), 'cache');

        // Assert
        self::assertContains('Removed integration module ' . $integration . ' (its capabilities are no longer both enabled).', $changes);
        self::assertNotContains($integration, $afterConsole);
        self::assertNotContains($integration, $this->workspace->project()->modules);
        self::assertContains('Trunk\\Console\\ConsoleModule', $this->workspace->project()->modules);
    }

    public function test_sync_repairs_a_hand_edited_module_list_and_is_idempotent(): void
    {
        // Arrange
        $this->workspace->manifest(['Trunk\\Cache\\CacheModule', 'Trunk\\Console\\ConsoleModule', 'Trunk\\Tests\\Fixtures\\Modules\\AlphaModule']);
        $manager = new CapabilityManager();

        // Act
        $first = $manager->sync($this->workspace->project());
        $second = $manager->sync($this->workspace->project());

        // Assert
        self::assertContains('Trunk\\Cache\\Console\\CacheConsoleModule', $this->workspace->project()->modules);
        self::assertStringContainsString('Added integration module', $first[0]);
        self::assertSame(['Integrations are already in sync.'], $second);
    }
}

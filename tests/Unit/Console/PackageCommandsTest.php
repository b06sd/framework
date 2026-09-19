<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Console\Commands\PackageInstallCommand;
use Trunk\Console\Commands\PackageListCommand;
use Trunk\Console\Commands\PackageRemoveCommand;
use Trunk\Console\Input\Input;
use Trunk\Console\Process\ProcessLauncher;
use Trunk\Console\Process\ProcessRunner;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Capability\CapabilityManager;
use Trunk\Foundation\Exception\CapabilityException;
use Trunk\Tests\Support\CapabilityWorkspace;
use Trunk\Tests\Support\OutputCapture;
use Trunk\Tests\Support\SimulatedComposer;

final class PackageCommandsTest extends TestCase
{
    private CapabilityWorkspace $workspace;

    private OutputCapture $capture;

    protected function setUp(): void
    {
        $this->workspace = new CapabilityWorkspace();
        $this->capture = new OutputCapture();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanUp();
    }

    public function test_list_shows_enabled_available_and_installed_but_not_enabled_capabilities(): void
    {
        // Arrange
        $this->payments();

        // Act
        new PackageListCommand($this->workspace->project())->handle(Input::fromArgv(['trunk', 'package:list']), $this->capture->output);
        $out = $this->capture->stdout();

        // Assert
        self::assertMatchesRegularExpression('/http\s+enabled\s+trunkphp\/framework/', $out);
        self::assertMatchesRegularExpression('/cache\s+available\s+trunkphp\/framework/', $out);
        self::assertMatchesRegularExpression('/payments\s+installed, not enabled\s+acme\/payments/', $out);
    }

    public function test_a_built_in_capability_whose_dependencies_are_installed_is_enabled_without_running_composer(): void
    {
        // Arrange
        $this->workspace->installLibrary('psr/simple-cache');
        $composer = new SimulatedComposer();

        // Act
        $code = $this->install('cache', $composer);

        // Assert
        self::assertSame(0, $code);
        self::assertSame([], $composer->calls);
        self::assertContains('Trunk\\Cache\\CacheModule', $this->workspace->project()->modules);
        self::assertStringContainsString('Enabled Cache.', $this->capture->stdout());
    }

    public function test_a_built_in_capability_installs_the_composer_packages_it_needs_in_one_call_before_touching_trunk_php(): void
    {
        // Arrange
        $composer = new SimulatedComposer();

        // Act
        $code = $this->install('cache', $composer);

        // Assert
        self::assertSame(0, $code);
        self::assertSame([['composer', 'require', '--no-interaction', '--', 'psr/simple-cache:^3.0']], $composer->calls);
        self::assertContains('Trunk\\Cache\\CacheModule', $this->workspace->project()->modules);
    }

    public function test_a_failing_dependency_install_leaves_trunk_php_untouched(): void
    {
        // Arrange
        $before = (string) file_get_contents($this->workspace->base . '/trunk.php');

        // Act & Assert
        try {
            $this->install('cache', new SimulatedComposer(2));
            self::fail('Expected a CommandFailedException.');
        } catch (CommandFailedException $e) {
            self::assertStringContainsString('so nothing was changed in trunk.php', $e->getMessage());
        }

        self::assertSame($before, (string) file_get_contents($this->workspace->base . '/trunk.php'));
    }

    public function test_an_external_package_is_fetched_with_a_fixed_composer_argument_array_and_then_enabled(): void
    {
        // Arrange
        $workspace = $this->workspace;
        $composer = new SimulatedComposer(0, static function (array $argv) use ($workspace): void {
            $workspace->installPackage('acme/payments', ['id' => 'payments', 'name' => 'Payments', 'modules' => ['Trunk\\Tests\\Fixtures\\Modules\\BetaModule']]);
        });

        // Act
        $code = $this->install('acme/payments:^1.2', $composer);

        // Assert
        self::assertSame(0, $code);
        self::assertSame([['composer', 'require', '--no-interaction', '--', 'acme/payments:^1.2']], $composer->calls);
        self::assertContains('Trunk\\Tests\\Fixtures\\Modules\\BetaModule', $this->workspace->project()->modules);
    }

    public function test_an_already_installed_package_is_enabled_without_running_composer_again(): void
    {
        // Arrange
        $this->payments();
        $composer = new SimulatedComposer();

        // Act
        $this->install('acme/payments', $composer);

        // Assert
        self::assertSame([], $composer->calls);
        self::assertContains('Trunk\\Tests\\Fixtures\\Modules\\BetaModule', $this->workspace->project()->modules);
    }

    public function test_a_failing_composer_leaves_trunk_php_untouched(): void
    {
        // Arrange
        $before = (string) file_get_contents($this->workspace->base . '/trunk.php');

        // Act & Assert
        try {
            $this->install('acme/payments', new SimulatedComposer(2));
            self::fail('Expected a CommandFailedException.');
        } catch (CommandFailedException $e) {
            self::assertStringContainsString('exit code 2', $e->getMessage());
            self::assertSame($before, file_get_contents($this->workspace->base . '/trunk.php'));
        }
    }

    public function test_a_package_without_trunk_metadata_is_reported_not_enabled(): void
    {
        // Arrange
        $workspace = $this->workspace;
        $composer = new SimulatedComposer(0, static function (array $argv) use ($workspace): void {
            mkdir($workspace->base . '/vendor/composer', 0o755, true);
            file_put_contents($workspace->base . '/vendor/composer/installed.json', '{"packages":[{"name":"acme/plain","install-path":"../acme/plain"}]}');
        });
        $before = (string) file_get_contents($this->workspace->base . '/trunk.php');

        // Act
        $code = $this->install('acme/plain', $composer);

        // Assert
        self::assertSame(0, $code);
        self::assertStringContainsString('does not declare a Trunk capability', $this->capture->stdout());
        self::assertSame($before, file_get_contents($this->workspace->base . '/trunk.php'));
    }

    public function test_unknown_capabilities_are_explained(): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(CapabilityException::class);
        $this->expectExceptionMessage('trunk package:list');
        $this->install('teleporter', new SimulatedComposer());
    }

    public function test_remove_disables_a_capability_and_only_purge_uninstalls_the_composer_package(): void
    {
        // Arrange
        $this->payments();
        $this->install('payments', new SimulatedComposer());
        $withoutPurge = new SimulatedComposer();
        $withPurge = new SimulatedComposer();

        // Act
        $this->remove('payments', $withoutPurge);
        $this->install('payments', new SimulatedComposer());
        $this->remove('acme/payments', $withPurge, true);

        // Assert
        self::assertSame([], $withoutPurge->calls);
        self::assertSame([['composer', 'remove', '--no-interaction', '--', 'acme/payments']], $withPurge->calls);
        self::assertNotContains('Trunk\\Tests\\Fixtures\\Modules\\BetaModule', $this->workspace->project()->modules);
    }

    public function test_built_in_capabilities_cannot_be_purged_and_unknown_targets_are_reported(): void
    {
        // Arrange
        $this->install('cache', new SimulatedComposer());
        $composer = new SimulatedComposer();
        $messages = [];

        // Act
        foreach ([['cache', true], ['nothing-here', false], ['acme/unknown', false]] as [$target, $purge]) {
            try {
                $this->remove($target, $composer, $purge);
            } catch (CommandFailedException $e) {
                $messages[] = $e->getMessage();
            }
        }

        // Assert
        self::assertCount(3, $messages);
        self::assertStringContainsString('ships with trunkphp/framework', $messages[0]);
        self::assertStringContainsString('not a known capability', $messages[1]);
        self::assertStringContainsString('not a known capability', $messages[2]);
        self::assertSame([], $composer->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostilePackages(): iterable
    {
        yield 'option injection' => ['--evil'];
        yield 'short option' => ['-v'];
        yield 'shell chain' => ['vendor/name; rm -rf /'];
        yield 'and chain' => ['vendor/name && touch /tmp/x'];
        yield 'substitution' => ['vendor/$(id)'];
        yield 'traversal' => ['../../x'];
        yield 'no vendor' => ['justname'];
        yield 'uppercase' => ['Vendor/Name'];
        yield 'space' => ['vendor/na me'];
        yield 'newline' => ["vendor/name\n--dev"];
        yield 'constraint option smuggling' => ['vendor/name:^1 --dev'];
        yield 'empty' => [''];
        yield 'url' => ['https://evil.test/x.zip'];
    }

    #[DataProvider('hostilePackages')]
    public function test_composer_is_never_given_anything_that_is_not_a_plain_package_name(string $package): void
    {
        // Arrange
        $composer = new SimulatedComposer();
        $runner = new ProcessRunner($composer);

        // Act & Assert
        try {
            $runner->composerRequireCommand($package);
            self::fail('Expected a CommandFailedException for ' . $package);
        } catch (CommandFailedException) {
            self::assertSame([], $composer->calls);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validPackages(): iterable
    {
        yield 'plain' => ['acme/payments'];
        yield 'constraint' => ['acme/payments:^1.2'];
        yield 'dev branch' => ['acme/pay-ments:dev-main'];
        yield 'dots' => ['acme/pay.ments:~2.0|^3'];
    }

    #[DataProvider('validPackages')]
    public function test_ordinary_package_names_and_constraints_are_accepted(string $package): void
    {
        // Arrange
        $runner = new ProcessRunner(new SimulatedComposer());

        // Act
        $argv = $runner->composerRequireCommand($package);

        // Assert
        self::assertSame(['composer', 'require', '--no-interaction', '--', $package], $argv);
    }

    private function install(string $target, ProcessLauncher $launcher): int
    {
        return new PackageInstallCommand($this->workspace->project(), new CapabilityManager(), new ProcessRunner($launcher))
            ->handle(Input::fromArgv(['trunk', 'package:install', $target]), $this->capture->output);
    }

    private function remove(string $target, ProcessLauncher $launcher, bool $purge = false): int
    {
        return new PackageRemoveCommand($this->workspace->project(), new CapabilityManager(), new ProcessRunner($launcher))
            ->handle(Input::fromArgv(['trunk', 'package:remove', $target, ...($purge ? ['--purge'] : [])]), $this->capture->output);
    }

    private function payments(): void
    {
        $this->workspace->installPackage('acme/payments', ['id' => 'payments', 'name' => 'Payments', 'modules' => ['Trunk\\Tests\\Fixtures\\Modules\\BetaModule']]);
    }
}

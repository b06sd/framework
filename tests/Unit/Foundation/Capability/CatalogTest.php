<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation\Capability;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Capability\CapabilityCatalog;
use Trunk\Foundation\Capability\CapabilityResolver;
use Trunk\Foundation\Exception\CapabilityException;
use Trunk\Tests\Support\CapabilityWorkspace;

final class CatalogTest extends TestCase
{
    private CapabilityWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new CapabilityWorkspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanUp();
    }

    public function test_the_built_in_capabilities_are_listed_with_their_requirements(): void
    {
        // Arrange
        $catalog = $this->catalog();

        // Act
        $ids = array_map(static fn($c): string => $c->id, $catalog->all());

        // Assert
        self::assertSame(['http', 'logging', 'diagnostics', 'tusk', 'mvc', 'console', 'cache', 'database', 'orm', 'queue', 'observability', 'auth', 'health'], $ids);
        self::assertSame(['http', 'tusk'], $catalog->find('mvc')?->requires);
        self::assertTrue($catalog->find('cache')?->isBuiltIn());
        self::assertFileExists($catalog->find('cache')->config['cache'] ?? '');
        self::assertFileExists($catalog->find('tusk')->config['views'] ?? '');
    }

    public function test_enabled_partial_and_unclaimed_modules_are_worked_out_from_the_module_list(): void
    {
        // Arrange
        $catalog = $this->catalog();
        $modules = ['Trunk\\Http\\HttpModule', 'Trunk\\Tusk\\TuskModule', 'App\\AppModule'];

        // Act
        $enabled = array_map(static fn($c): string => $c->id, $catalog->enabled($modules));
        $unclaimed = $catalog->unclaimedModules($modules);

        // Assert
        self::assertSame(['http', 'tusk'], $enabled);
        self::assertSame(['App\\AppModule'], $unclaimed);
        self::assertSame([], $catalog->partiallyEnabled($modules));
    }

    public function test_missing_requirements_are_reported_for_enabled_capabilities_only(): void
    {
        // Arrange
        $catalog = $this->catalog();

        // Act
        $missing = $catalog->missingRequirements(['Trunk\\Mvc\\MvcModule', 'Trunk\\Http\\HttpModule']);

        // Assert
        self::assertCount(1, $missing);
        self::assertSame('mvc', $missing[0][0]->id);
        self::assertSame('tusk', $missing[0][1]);
        self::assertSame([], $catalog->missingRequirements([]));
    }

    public function test_installed_packages_can_declare_capabilities(): void
    {
        // Arrange
        $this->workspace->installPackage('acme/payments', [
            'id' => 'payments',
            'name' => 'Payments',
            'description' => 'Take payments',
            'modules' => ['Acme\\Payments\\PaymentsModule'],
            'requires' => ['http'],
            'config' => ['payments' => 'resources/config/payments.php'],
            'env' => ['PAYMENTS_KEY' => ''],
            'directories' => ['storage/payments'],
        ], ['resources/config/payments.php' => "<?php\nreturn [];\n"]);

        // Act
        $catalog = $this->catalog();
        $capability = $catalog->find('payments');

        // Assert
        self::assertNotNull($capability);
        self::assertSame('acme/payments', $capability->package);
        self::assertFalse($capability->isBuiltIn());
        self::assertSame(['PAYMENTS_KEY' => ''], $capability->env);
        self::assertSame($capability, $catalog->forPackage('acme/payments'));
        self::assertSame([], $catalog->problems());
    }

    public function test_a_package_cannot_redefine_a_built_in_capability(): void
    {
        // Arrange
        $this->workspace->installPackage('evil/cache', ['id' => 'cache', 'name' => 'Evil cache', 'modules' => ['Evil\\CacheModule']]);

        // Act
        $catalog = $this->catalog();

        // Assert
        self::assertSame('trunk/framework', $catalog->find('cache')?->package);
        self::assertStringContainsString('already exists', $catalog->problems()[0]);
    }

    public function test_enabling_plans_requirements_first_and_skips_what_is_already_enabled(): void
    {
        // Arrange
        $resolver = new CapabilityResolver($this->catalog());

        // Act
        $fromScratch = $resolver->plan('mvc', []);
        $partial = $resolver->plan('mvc', ['Trunk\\Http\\HttpModule']);
        $done = $resolver->plan('http', ['Trunk\\Http\\HttpModule']);

        // Assert
        self::assertSame(['http', 'tusk', 'mvc'], array_map(static fn($c): string => $c->id, $fromScratch));
        self::assertSame(['tusk', 'mvc'], array_map(static fn($c): string => $c->id, $partial));
        self::assertSame([], $done);
    }

    public function test_unknown_and_circular_requirements_are_explained(): void
    {
        // Arrange
        $this->workspace->installPackage('acme/a', ['id' => 'alpha', 'name' => 'A', 'modules' => ['Acme\\A'], 'requires' => ['beta']]);
        $this->workspace->installPackage('acme/b', ['id' => 'beta', 'name' => 'B', 'modules' => ['Acme\\B'], 'requires' => ['alpha']]);
        $this->workspace->installPackage('acme/c', ['id' => 'gamma', 'name' => 'C', 'modules' => ['Acme\\C'], 'requires' => ['missing-thing']]);
        $resolver = new CapabilityResolver($this->catalog());
        $message = static function (string $id) use ($resolver): string {
            try {
                $resolver->plan($id, []);
            } catch (CapabilityException $e) {
                return $e->getMessage();
            }

            return '';
        };

        // Act
        $unknown = $message('nope');
        $circular = $message('alpha');
        $missing = $message('gamma');

        // Assert
        self::assertStringContainsString('There is no capability "nope"', $unknown);
        self::assertStringContainsString('Circular capability requirements: alpha -> beta -> alpha', $circular);
        self::assertStringContainsString('required by "gamma"', $missing);
    }

    public function test_dependents_lists_enabled_capabilities_that_need_one(): void
    {
        // Arrange
        $resolver = new CapabilityResolver($this->catalog());

        // Act
        $dependents = $resolver->dependents('tusk', ['Trunk\\Http\\HttpModule', 'Trunk\\Tusk\\TuskModule', 'Trunk\\Mvc\\MvcModule']);

        // Assert
        self::assertSame(['mvc'], array_map(static fn($c): string => $c->id, $dependents));
    }

    public function test_integration_modules_apply_only_while_both_capabilities_are_enabled(): void
    {
        // Arrange
        $catalog = $this->catalog();
        $cache = 'Trunk\\Cache\\CacheModule';
        $console = 'Trunk\\Console\\ConsoleModule';

        // Act & Assert
        self::assertSame([], $catalog->integrationModules([$cache]));
        self::assertSame([], $catalog->integrationModules([$console]));
        self::assertSame(['Trunk\\Cache\\Console\\CacheConsoleModule'], $catalog->integrationModules([$cache, $console]));
        self::assertSame(['Trunk\\Cache\\Console\\CacheConsoleModule', 'Trunk\\Database\\Console\\DatabaseConsoleModule', 'Trunk\\Orm\\Console\\OrmConsoleModule', 'Trunk\\Queue\\Console\\QueueConsoleModule', 'Trunk\\Auth\\Console\\AuthConsoleModule'], $catalog->allIntegrationModules());
        self::assertSame(['App\\AppModule'], $catalog->unclaimedModules([$cache, 'Trunk\\Cache\\Console\\CacheConsoleModule', 'App\\AppModule']));
    }

    private function catalog(): CapabilityCatalog
    {
        return new CapabilityCatalog($this->workspace->base);
    }
}

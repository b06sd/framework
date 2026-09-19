<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use LogicException;
use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Capability\CapabilityCatalog;
use Trunk\Foundation\Capability\ComposerRequirements;
use Trunk\Tests\Support\CapabilityWorkspace;

final class ComposerRequirementsTest extends TestCase
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

    public function test_the_http_capability_needs_the_four_psr_http_packages_and_cache_needs_simple_cache(): void
    {
        // Arrange
        $catalog = new CapabilityCatalog($this->workspace->base);
        $requirements = new ComposerRequirements();

        // Act
        $http = $requirements->for([$catalog->find('http') ?? throw new LogicException()]);
        $all = $requirements->for($catalog->all());

        // Assert
        self::assertSame(['psr/http-factory', 'psr/http-message', 'psr/http-server-handler', 'psr/http-server-middleware'], array_keys($http));
        self::assertSame('^3.0', $all['psr/simple-cache']);
        self::assertSame('*', $all['ext-pdo']);
    }

    public function test_only_what_is_absent_from_installed_json_is_missing(): void
    {
        // Arrange
        $this->workspace->installLibrary('psr/http-message');
        $requirements = new ComposerRequirements();

        // Act
        $missing = $requirements->missing($this->workspace->base, ['psr/http-message' => '^2.0', 'psr/http-factory' => '^1.0']);

        // Assert
        self::assertSame(['psr/http-factory' => '^1.0'], $missing);
        self::assertSame(['psr/http-factory:^1.0'], $requirements->specs($missing));
        self::assertStringContainsString('composer require psr/http-factory:^1.0', $requirements->describe($missing));
    }

    public function test_a_package_that_replaces_another_satisfies_it(): void
    {
        // Arrange
        $file = $this->workspace->base . '/vendor/composer/installed.json';
        mkdir(\dirname($file), 0o755, true);
        file_put_contents($file, json_encode(['packages' => [['name' => 'acme/all-psr', 'replace' => ['psr/http-message' => '*']]]], \JSON_THROW_ON_ERROR));

        // Act
        $missing = new ComposerRequirements()->missing($this->workspace->base, ['psr/http-message' => '^2.0']);

        // Assert
        self::assertSame([], $missing);
    }

    public function test_a_missing_extension_is_reported_as_an_extension_not_a_composer_package(): void
    {
        // Arrange
        $requirements = new ComposerRequirements();

        // Act
        $missing = $requirements->missing($this->workspace->base, ['ext-trunk_no_such_extension' => '*', 'ext-json' => '*']);

        // Assert
        self::assertSame(['ext-trunk_no_such_extension' => '*'], $missing);
        self::assertSame([], $requirements->specs($missing), 'Composer cannot install an extension');
        self::assertSame('missing PHP extensions: trunk_no_such_extension', $requirements->describe($missing));
    }

    public function test_third_party_metadata_can_never_add_composer_requirements(): void
    {
        // Arrange
        $this->workspace->installPackage('acme/evil', ['id' => 'evil', 'name' => 'Evil', 'modules' => ['Acme\\EvilModule'], 'composer' => ['attacker/backdoor' => '*']]);
        $catalog = new CapabilityCatalog($this->workspace->base);

        // Act
        $requirements = new ComposerRequirements()->for($catalog->all());

        // Assert
        self::assertArrayNotHasKey('attacker/backdoor', $requirements);
    }
}

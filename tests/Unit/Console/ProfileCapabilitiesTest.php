<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Console\Scaffold\Profile;
use Trunk\Foundation\Capability\Capability;

final class ProfileCapabilitiesTest extends TestCase
{
    public function test_a_profile_is_just_a_starting_set_of_capabilities_resolved_requirements_first(): void
    {
        // Arrange

        // Act
        $web = array_map(static fn(Capability $c): string => $c->id, Profile::Web->plan());
        $mono = array_map(static fn(Capability $c): string => $c->id, Profile::SelfContained->plan());
        $cli = array_map(static fn(Capability $c): string => $c->id, Profile::Cli->plan());

        // Assert
        self::assertSame(['http', 'logging', 'diagnostics', 'tusk', 'mvc'], $web);
        self::assertSame(['http', 'logging', 'diagnostics', 'tusk', 'mvc', 'cache'], $mono);
        self::assertSame(['logging', 'diagnostics', 'console'], $cli);
    }

    public function test_modules_come_from_the_capabilities_with_the_application_module_last(): void
    {
        // Arrange

        // Act
        $api = Profile::Api->modules();
        $mono = Profile::SelfContained->modules();

        // Assert
        self::assertSame(['Trunk\\Http\\HttpModule', 'Trunk\\Foundation\\Logging\\LoggingModule', 'Trunk\\Foundation\\Diagnostics\\DiagnosticsModule', 'App\\AppModule'], $api);
        self::assertContains('Trunk\\Cache\\CacheModule', $mono);
        self::assertSame('App\\AppModule', end($mono));
    }

    public function test_an_api_profile_carries_no_view_capability(): void
    {
        // Arrange

        // Act
        $ids = array_map(static fn(Capability $c): string => $c->id, Profile::Api->plan());

        // Assert
        self::assertNotContains('tusk', $ids);
        self::assertNotContains('mvc', $ids);
        self::assertNotContains('console', $ids);
    }
}

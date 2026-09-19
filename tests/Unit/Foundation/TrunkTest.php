<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

final class TrunkTest extends TestCase
{
    public function test_trunk_is_alive(): void
    {
        // Arrange
        $package = 'trunkphp/framework';

        // Act
        $result = InstalledVersions::isInstalled($package);

        // Assert
        self::assertTrue($result);
    }
}

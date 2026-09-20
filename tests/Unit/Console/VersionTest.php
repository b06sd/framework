<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Console;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Console\Version;

final class VersionTest extends TestCase
{
    #[DataProvider('labels')]
    public function test_a_composer_version_is_shown_without_the_tag_prefix(?string $installed, string $expected): void
    {
        // Act and assert
        self::assertSame($expected, Version::label($installed));
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function labels(): iterable
    {
        yield 'tag' => ['v0.1.1', '0.1.1'];
        yield 'tag without prefix' => ['0.2.0', '0.2.0'];
        yield 'branch' => ['dev-main', 'dev-main'];
        yield 'unknown' => [null, 'dev'];
        yield 'blank' => ['  ', 'dev'];
        yield 'a branch that merely starts with v' => ['vendor-fix', 'vendor-fix'];
    }

    public function test_the_running_version_comes_from_composer_not_from_a_constant(): void
    {
        // Arrange
        $installed = InstalledVersions::getPrettyVersion('trunkphp/framework');

        // Act and assert
        self::assertSame(Version::label($installed), Version::current());
    }
}

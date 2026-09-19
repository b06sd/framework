<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation\Capability;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Capability\CapabilityCatalog;
use Trunk\Tests\Support\CapabilityWorkspace;

/**
 * Package metadata is untrusted input: every hostile shape is ignored and reported.
 */
final class MetadataSecurityTest extends TestCase
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

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function hostile(): iterable
    {
        $ok = ['id' => 'thing', 'name' => 'Thing', 'modules' => ['Acme\\ThingModule']];

        yield 'bad id' => [['id' => 'Bad ID!'] + $ok];
        yield 'multi-line name' => [['name' => "Thing\nInjected"] + $ok];
        yield 'no modules' => [['modules' => []] + $ok];
        yield 'module traversal' => [['modules' => ['Acme\\..\\..\\Evil']] + $ok];
        yield 'module injection' => [['modules' => ["A'); system('id'); //"]] + $ok];
        yield 'modules not a list' => [['modules' => 'Acme\\X'] + $ok];
        yield 'bad required id' => [['requires' => ['../etc']] + $ok];
        yield 'env newline injection' => [['env' => ['THING_KEY' => "1\nEVIL=payload"]] + $ok];
        yield 'env lowercase key' => [['env' => ['thing_key' => '1']] + $ok];
        yield 'env key injection' => [['env' => ["A=1\nB" => '1']] + $ok];
        yield 'config traversal' => [['config' => ['thing' => '../../../../etc/passwd.php']] + $ok];
        yield 'config absolute' => [['config' => ['thing' => '/etc/passwd.php']] + $ok];
        yield 'config missing' => [['config' => ['thing' => 'resources/nope.php']] + $ok];
        yield 'config not php' => [['config' => ['thing' => 'resources/config.txt']] + $ok];
        yield 'config bad name' => [['config' => ['../x' => 'resources/config/thing.php']] + $ok];
        yield 'integration bad capability id' => [['integrations' => ['../x' => ['Acme\\M']]] + $ok];
        yield 'integration bad class' => [['integrations' => ['console' => ["A'); system('id'); //"]]] + $ok];
        yield 'integration not a list' => [['integrations' => ['console' => 'Acme\\M']] + $ok];
        yield 'integration empty' => [['integrations' => ['console' => []]] + $ok];
        yield 'directory traversal' => [['directories' => ['../outside']] + $ok];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('hostile')]
    public function test_hostile_package_metadata_is_ignored_and_reported(array $metadata): void
    {
        // Arrange
        $this->workspace->installPackage('evil/thing', $metadata, ['resources/config/thing.php' => "<?php\nreturn [];\n", 'resources/config.txt' => 'x']);
        file_put_contents($this->workspace->base . '/outside.php', "<?php\nreturn [];\n");

        // Act
        $catalog = new CapabilityCatalog($this->workspace->base);

        // Assert
        self::assertNull($catalog->find('thing'));
        self::assertNull($catalog->find('bad id!'));
        self::assertCount(1, $catalog->problems());
        self::assertStringContainsString('evil/thing', $catalog->problems()[0]);
    }

    public function test_an_install_path_that_escapes_vendor_is_ignored(): void
    {
        // Arrange
        $this->workspace->installPackage('ok/thing', ['id' => 'thing', 'name' => 'Thing', 'modules' => ['Acme\\ThingModule']]);
        $file = $this->workspace->base . '/vendor/composer/installed.json';
        $json = str_replace('"install-path":"..\/ok\/thing"', '"install-path":"..\/..\/..\/..\/etc"', (string) file_get_contents($file));
        file_put_contents($file, $json);

        // Act
        $catalog = new CapabilityCatalog($this->workspace->base);

        // Assert
        self::assertNull($catalog->find('thing'));
        self::assertStringContainsString('install path is invalid', $catalog->problems()[0]);
    }

    public function test_a_broken_installed_json_is_reported_not_fatal(): void
    {
        // Arrange
        mkdir($this->workspace->base . '/vendor/composer', 0o755, true);
        file_put_contents($this->workspace->base . '/vendor/composer/installed.json', '{not json');

        // Act
        $catalog = new CapabilityCatalog($this->workspace->base);

        // Assert
        self::assertCount(13, $catalog->all());
        self::assertStringContainsString('not valid JSON', $catalog->problems()[0]);
    }
}

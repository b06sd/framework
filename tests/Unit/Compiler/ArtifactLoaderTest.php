<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use stdClass;
use Trunk\Compiler\ArtifactLoader;
use Trunk\Compiler\Exception\ArtifactException;
use Trunk\Support\Directory;

final class ArtifactLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-artifact-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    public function test_it_returns_the_object_the_artifact_returns(): void
    {
        // Arrange
        file_put_contents($this->directory . '/a.php', '<?php return new \\stdClass();');

        // Act
        $artifact = ArtifactLoader::load($this->directory . '/a.php', stdClass::class, 'thing');

        // Assert
        self::assertInstanceOf(stdClass::class, $artifact);
    }

    public function test_a_missing_artifact_says_to_build_and_does_not_leak_the_path(): void
    {
        // Act & Assert
        try {
            ArtifactLoader::load($this->directory . '/missing.php', stdClass::class, 'thing');
            self::fail('expected an exception');
        } catch (ArtifactException $e) {
            self::assertSame('The thing has not been built for production. Run `trunk build` first.', $e->getMessage());
        }
    }

    public function test_an_artifact_of_the_wrong_type_is_rejected(): void
    {
        // Arrange
        file_put_contents($this->directory . '/b.php', '<?php return [1];');

        // Act & Assert
        $this->expectException(ArtifactException::class);
        $this->expectExceptionMessage('The thing build is not valid (b.php). Run `trunk build` again.');
        ArtifactLoader::load($this->directory . '/b.php', stdClass::class, 'thing');
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation\Capability;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Capability\Capability;
use Trunk\Foundation\Capability\CapabilityPublisher;
use Trunk\Foundation\Project\Project;
use Trunk\Support\Directory;

final class CapabilityPublisherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/trunk-publisher-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/project', 0o755, true);
        file_put_contents($this->root . '/tokens.tusk.php', "<p>stub</p>\n");
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->root);
    }

    public function test_a_view_is_published_into_the_project_and_an_edited_one_is_kept(): void
    {
        // Arrange
        $project = new Project($this->root . '/project', 'shop', 'web', []);
        $capability = new Capability('api-tokens', 'API token page', '', ['App\\M'], views: ['account/tokens' => $this->root . '/tokens.tusk.php']);
        $publisher = new CapabilityPublisher();

        // Act
        $first = $publisher->publish($project, $capability);
        file_put_contents($this->root . '/project/resources/views/account/tokens.tusk.php', "<p>mine</p>\n");
        $second = $publisher->publish($project, $capability);

        // Assert
        self::assertSame(['Created resources/views/account/tokens.tusk.php.'], $first);
        self::assertSame(['Kept your existing resources/views/account/tokens.tusk.php.'], $second);
        self::assertSame("<p>mine</p>\n", file_get_contents($this->root . '/project/resources/views/account/tokens.tusk.php'));
    }
}

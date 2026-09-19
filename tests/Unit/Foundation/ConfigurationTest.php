<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Exception\ConfigurationException;

final class ConfigurationTest extends TestCase
{
    public function test_nested_values_are_read_with_dot_notation(): void
    {
        // Arrange
        $config = $this->config();

        // Act
        $name = $config->string('app.name');

        // Assert
        self::assertSame('Trunk', $name);
    }

    public function test_typed_getters_return_matching_types(): void
    {
        // Arrange
        $config = $this->config();

        // Act
        $values = [$config->int('app.workers'), $config->bool('app.debug'), $config->array('app.tags')];

        // Assert
        self::assertSame([4, false, ['a', 'b']], $values);
    }

    public function test_has_and_get_handle_missing_keys_without_throwing(): void
    {
        // Arrange
        $config = $this->config();

        // Act
        $has = $config->has('app.missing');
        $value = $config->get('app.missing', 'fallback');

        // Assert
        self::assertFalse($has);
        self::assertSame('fallback', $value);
    }

    public function test_typed_getter_rejects_a_missing_key(): void
    {
        // Arrange
        $config = $this->config();

        // Act & Assert
        $this->expectException(ConfigurationException::class);
        $config->string('app.missing');
    }

    public function test_typed_getter_rejects_a_value_of_the_wrong_type(): void
    {
        // Arrange
        $config = $this->config();

        // Act & Assert
        $this->expectException(ConfigurationException::class);
        $config->int('app.name');
    }

    public function test_from_file_rejects_a_missing_file(): void
    {
        // Arrange
        $path = __DIR__ . '/does-not-exist.php';

        // Act & Assert
        $this->expectException(ConfigurationException::class);
        Configuration::fromFile($path);
    }

    public function test_from_file_loads_a_returned_array(): void
    {
        // Arrange
        $path = sys_get_temp_dir() . '/trunk-config-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, "<?php return ['a' => ['b' => 1]];");

        // Act
        $value = Configuration::fromFile($path)->int('a.b');
        unlink($path);

        // Assert
        self::assertSame(1, $value);
    }
    private function config(): Configuration
    {
        return new Configuration([
            'app' => ['name' => 'Trunk', 'workers' => 4, 'debug' => false, 'tags' => ['a', 'b']],
        ]);
    }
}

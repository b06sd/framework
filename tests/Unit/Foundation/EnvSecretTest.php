<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\EnvSecret;
use Trunk\Foundation\Exception\ConfigurationException;
use Trunk\Foundation\Runtime;
use Trunk\Support\Directory;

final class EnvSecretTest extends TestCase
{
    public function test_a_secret_resolves_from_the_environment_at_any_depth_and_never_appears_in_dumps(): void
    {
        // Arrange
        $config = new Configuration(['db' => ['connections' => ['main' => ['user' => 'root', 'password' => new EnvSecret('DB_PASSWORD')]]], 'token' => new EnvSecret('API_TOKEN', 'fallback')], ['DB_PASSWORD' => 'hunter2']);

        // Act
        $connections = $config->array('db.connections');

        // Assert
        self::assertSame(['main' => ['user' => 'root', 'password' => 'hunter2']], $connections);
        self::assertSame('fallback', $config->string('token'), 'a non-secret default is used when the variable is unset');
        self::assertStringNotContainsString('hunter2', print_r(new EnvSecret('DB_PASSWORD'), true));
        self::assertStringNotContainsString('hunter2', var_export(new EnvSecret('DB_PASSWORD'), true));
        self::assertStringContainsString('[secret]', (string) json_encode(new EnvSecret('DB_PASSWORD')->__debugInfo()));
    }

    public function test_a_missing_secret_is_an_actionable_error_at_read_time(): void
    {
        // Arrange
        $config = new Configuration(['password' => new EnvSecret('DB_PASSWORD')], []);

        // Act & Assert
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The secret DB_PASSWORD is not set');
        $config->string('password');
    }

    public function test_while_building_secrets_stay_references_so_a_missing_production_value_cannot_fail_the_build(): void
    {
        // Arrange
        $config = new Configuration(['password' => new EnvSecret('DB_PASSWORD')], [], false);

        // Act
        $value = $config->get('password');

        // Assert
        self::assertInstanceOf(EnvSecret::class, $value);
    }

    public function test_the_exported_reference_round_trips_and_carries_no_value(): void
    {
        // Arrange
        $runtime = new Runtime(Environment::Production, false, '/app', ['DB_PASSWORD' => 'hunter2']);

        // Act
        $exported = var_export(['password' => $runtime->secret('DB_PASSWORD', '')], true);
        $restored = eval('return ' . $exported . ';');

        // Assert
        self::assertStringNotContainsString('hunter2', $exported);
        self::assertIsArray($restored);
        self::assertEquals(new EnvSecret('DB_PASSWORD', ''), $restored['password']);
    }

    public function test_secret_names_are_validated(): void
    {
        // Arrange
        $rejected = 0;

        // Act
        foreach (['db_password', 'DB PASSWORD', '', 'A;B', "X\n", str_repeat('A', 65), '1ABC'] as $name) {
            try {
                new EnvSecret($name);
            } catch (ConfigurationException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(7, $rejected);
    }

    public function test_directories_can_be_restricted_to_owner_and_group(): void
    {
        // Arrange
        $root = sys_get_temp_dir() . '/trunk-restrict-' . bin2hex(random_bytes(4));
        mkdir($root . '/sub', 0o777, true);
        file_put_contents($root . '/sub/a.php', 'x');
        chmod($root . '/sub/a.php', 0o666);

        // Act
        new Directory()->restrict($root);

        // Assert
        self::assertSame(['0750', '0750', '0640'], [substr(\sprintf('%o', fileperms($root)), -4), substr(\sprintf('%o', fileperms($root . '/sub')), -4), substr(\sprintf('%o', fileperms($root . '/sub/a.php')), -4)]);
        new Directory()->remove($root);
    }
}

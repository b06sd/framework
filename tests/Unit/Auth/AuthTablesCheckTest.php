<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Trunk\Auth\Doctor\AuthTablesCheck;
use Trunk\Auth\Settings\SessionSettings;
use Trunk\Database\Schema\Schema;
use Trunk\Tests\Support\DatabaseHarness;

final class AuthTablesCheckTest extends TestCase
{
    public function test_it_reports_each_missing_table_with_its_fix_and_ok_once_they_exist(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->sqlite();
        $schema = new Schema($connection);
        $check = new AuthTablesCheck($schema, new SessionSettings());

        // Act: nothing, then a project from before one-time links existed, then a current one
        $before = $check->check();
        $schema->create('trunk_sessions', static function ($table): void {
            $table->string('id', 64)->primary();
        });
        $upgraded = $check->check();
        $schema->create('trunk_auth_links', static function ($table): void {
            $table->string('id', 32)->primary();
        });
        $after = $check->check();

        // Assert
        self::assertSame('Auth tables', $check->name());
        self::assertFalse($before->ok);
        self::assertStringContainsString('trunk_sessions', (string) $before->message);
        self::assertSame('trunk auth:table && trunk migrate', $before->fix);
        self::assertFalse($upgraded->ok);
        self::assertStringContainsString('trunk_auth_links', (string) $upgraded->message);
        self::assertSame('trunk auth:table --links && trunk migrate', $upgraded->fix);
        self::assertTrue($after->ok);
    }
}

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
    public function test_it_reports_the_missing_table_with_the_fix_and_ok_once_it_exists(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->sqlite();
        $schema = new Schema($connection);
        $check = new AuthTablesCheck($schema, new SessionSettings());

        // Act
        $before = $check->check();
        $schema->create('trunk_sessions', static function ($table): void {
            $table->string('id', 64)->primary();
        });
        $after = $check->check();

        // Assert
        self::assertSame('Auth tables', $check->name());
        self::assertFalse($before->ok);
        self::assertStringContainsString('trunk_sessions', (string) $before->message);
        self::assertSame('trunk auth:table && trunk migrate', $before->fix);
        self::assertTrue($after->ok);
    }
}

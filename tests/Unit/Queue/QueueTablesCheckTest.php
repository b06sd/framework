<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Trunk\Database\Schema\Schema;
use Trunk\Queue\Doctor\QueueTablesCheck;
use Trunk\Tests\Support\DatabaseHarness;

final class QueueTablesCheckTest extends TestCase
{
    public function test_it_reports_the_missing_table_with_the_fix_and_ok_once_it_exists(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->sqlite();
        $schema = new Schema($connection);
        $check = new QueueTablesCheck($schema, 'trunk_jobs');

        // Act
        $before = $check->check();
        $schema->create('trunk_jobs', static function ($table): void {
            $table->id();
        });
        $after = $check->check();

        // Assert
        self::assertSame('Queue tables', $check->name());
        self::assertFalse($before->ok);
        self::assertStringContainsString('trunk_jobs', (string) $before->message);
        self::assertSame('trunk queue:table && trunk migrate', $before->fix);
        self::assertTrue($after->ok);
    }
}

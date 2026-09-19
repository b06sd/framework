<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Database\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\MySqlSchemaGrammar;
use Trunk\Database\Schema\PostgresSchemaGrammar;
use Trunk\Database\Schema\SchemaGrammar;
use Trunk\Database\Schema\SqliteSchemaGrammar;

/**
 * Golden DDL per driver. SQLite is also exercised for real elsewhere; MySQL and PostgreSQL are
 * pinned here so their output cannot drift silently.
 */
final class SchemaGrammarTest extends TestCase
{
    /**
     * @return iterable<string, array{SchemaGrammar, string}>
     */
    public static function grammars(): iterable
    {
        yield 'sqlite' => [new SqliteSchemaGrammar(), 'CREATE TABLE "posts" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "title" VARCHAR(120) NOT NULL, "body" TEXT NULL, "live" BOOLEAN NOT NULL DEFAULT 0, "price" NUMERIC(8, 2) NOT NULL, "user_id" INTEGER NOT NULL, "created_at" DATETIME NULL, "updated_at" DATETIME NULL, FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE)'];
        yield 'mysql' => [new MySqlSchemaGrammar(), 'CREATE TABLE `posts` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` VARCHAR(120) NOT NULL, `body` TEXT NULL, `live` TINYINT(1) NOT NULL DEFAULT 0, `price` DECIMAL(8, 2) NOT NULL, `user_id` BIGINT UNSIGNED NOT NULL, `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL, FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE)'];
        yield 'postgres' => [new PostgresSchemaGrammar(), 'CREATE TABLE "posts" ("id" BIGSERIAL PRIMARY KEY, "title" VARCHAR(120) NOT NULL, "body" TEXT NULL, "live" BOOLEAN NOT NULL DEFAULT FALSE, "price" NUMERIC(8, 2) NOT NULL, "user_id" BIGINT NOT NULL, "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NULL, "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NULL, FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE)'];
    }

    #[DataProvider('grammars')]
    public function test_create_table_ddl_per_driver(SchemaGrammar $grammar, string $expected): void
    {
        // Arrange
        $blueprint = $this->posts();

        // Act
        $statements = $grammar->compileCreate($blueprint);

        // Assert
        self::assertSame($expected, $statements[0]);
        self::assertCount(2, $statements);
        self::assertStringContainsString('_title_unique', $statements[1]);
    }

    #[DataProvider('grammars')]
    public function test_no_column_definition_repeats_its_nullability(SchemaGrammar $grammar, string $unused): void
    {
        // Arrange & Act
        $sql = $grammar->compileCreate($this->posts())[0];

        // Assert
        self::assertStringNotContainsString('NULL NULL', $sql);
        self::assertStringNotContainsString('NOT NOT', $sql);
    }

    #[DataProvider('grammars')]
    public function test_hostile_table_and_column_names_are_rejected_before_any_ddl_exists(SchemaGrammar $grammar, string $unused): void
    {
        // Arrange
        $rejected = 0;

        // Act
        foreach (['users; DROP TABLE x', 'a"b', 'a`b', ''] as $name) {
            try {
                $grammar->compileCreate(new Blueprint($name));
            } catch (Throwable) {
                ++$rejected;
            }
        }

        try {
            $blueprint = new Blueprint('t');
            $blueprint->string('a") --');
            $grammar->compileCreate($blueprint);
        } catch (Throwable) {
            ++$rejected;
        }

        // Assert
        self::assertSame(5, $rejected);
    }

    #[DataProvider('grammars')]
    public function test_drop_and_rename(SchemaGrammar $grammar, string $unused): void
    {
        // Arrange
        $q = $grammar instanceof MySqlSchemaGrammar ? '`' : '"';

        // Act & Assert
        self::assertSame("DROP TABLE IF EXISTS {$q}a{$q}", $grammar->compileDrop('a', true));
        self::assertSame("DROP TABLE {$q}a{$q}", $grammar->compileDrop('a', false));
        self::assertStringContainsString("{$q}b{$q}", $grammar->compileRename('a', 'b'));
    }
    private function posts(): Blueprint
    {
        $blueprint = new Blueprint('posts');
        $blueprint->id();
        $blueprint->string('title', 120)->unique();
        $blueprint->text('body')->nullable();
        $blueprint->boolean('live')->default(false);
        $blueprint->decimal('price', 8, 2);
        $blueprint->foreignId('user_id')->constrained('users')->onDelete('cascade');
        $blueprint->timestamps();

        return $blueprint;
    }
}

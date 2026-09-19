<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Orm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;
use Trunk\Orm\Mapping\DevelopmentRegistry;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Tests\Fixtures\Orm\Tag;
use Trunk\Tests\Fixtures\Orm\TagMap;

/**
 * Real MySQL and PostgreSQL, only when TRUNK_TEST_MYSQL_* / TRUNK_TEST_PGSQL_* (HOST, DATABASE,
 * USER, PASSWORD, optional PORT) are set. Skipped otherwise.
 */
final class LiveDriversTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('drivers')]
    public function test_persist_flush_find_update_and_payloads_on_a_real_server(string $driver): void
    {
        // Arrange
        $prefix = 'TRUNK_TEST_' . ($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_';
        $host = getenv($prefix . 'HOST');
        $database = getenv($prefix . 'DATABASE');

        if ($host === false || $database === false) {
            self::markTestSkipped('Set ' . $prefix . 'HOST and ' . $prefix . 'DATABASE to run this suite.');
        }

        $connection = new ConnectionFactory()->make('live', ['driver' => $driver, 'host' => $host, 'database' => $database, 'username' => getenv($prefix . 'USER') ?: '', 'password' => getenv($prefix . 'PASSWORD') ?: '', 'port' => (int) (getenv($prefix . 'PORT') ?: ($driver === 'mysql' ? 3306 : 5432))]);
        $schema = new Schema($connection);
        $schema->dropIfExists('tags');
        $schema->create('tags', function (Blueprint $t): void {
            $t->id();
            $t->string('label');
        });
        $payload = "'); DROP TABLE tags; --";

        try {
            $manager = new EntityManager($connection, new DevelopmentRegistry([TagMap::class]));

            // Act
            $tag = new Tag(label: $payload);
            $manager->persist($tag);
            $manager->flush();
            $tag->label = 'renamed';
            $manager->flush();
            $fresh = new EntityManager($connection, new DevelopmentRegistry([TagMap::class]))->repository(Tag::class)->findOrFail((int) $tag->id);

            // Assert
            self::assertSame('renamed', $fresh->label);
            self::assertTrue($schema->hasTable('tags'));
        } finally {
            $schema->dropIfExists('tags');
        }
    }
}

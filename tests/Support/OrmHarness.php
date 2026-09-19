<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\QueryLog;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;
use Trunk\Orm\Mapping\DevelopmentRegistry;
use Trunk\Orm\Mapping\MappingRegistry;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Tests\Fixtures\Orm\CustomerMap;
use Trunk\Tests\Fixtures\Orm\OrderMap;
use Trunk\Tests\Fixtures\Orm\ProfileMap;
use Trunk\Tests\Fixtures\Orm\TagMap;

/**
 * An in-memory SQLite database with the fixture tables, plus an EntityManager over it.
 */
final class OrmHarness
{
    public readonly Connection $connection;

    public readonly QueryLog $log;

    public function __construct(public readonly MappingRegistry $registry = new DevelopmentRegistry([CustomerMap::class, OrderMap::class, ProfileMap::class, TagMap::class]))
    {
        $this->log = new QueryLog();
        $this->connection = new DatabaseHarness()->sqlite($this->log);
        $schema = new Schema($this->connection);
        $schema->create('customers', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password_hash');
            $t->string('status');
            $t->string('nickname')->nullable();
            $t->boolean('active')->default(true);
            $t->float('balance')->default(0);
            $t->text('settings');
            $t->dateTime('created_at');
            $t->dateTime('deleted_at')->nullable();
            $t->integer('version')->default(1);
        });
        $schema->create('orders', function (Blueprint $t): void {
            $t->id();
            $t->integer('customer_id');
            $t->float('total');
            $t->string('note')->nullable();
        });
        $schema->create('profiles', function (Blueprint $t): void {
            $t->id();
            $t->integer('customer_id');
            $t->string('bio');
        });
        $schema->create('tags', function (Blueprint $t): void {
            $t->id();
            $t->string('label');
        });
        $schema->create('order_tag', function (Blueprint $t): void {
            $t->integer('order_id');
            $t->integer('tag_id');
        });
        $this->log->clear();
    }

    /**
     * @param list<\Trunk\Orm\Repository\Scope> $scopes
     */
    public function manager(array $scopes = []): EntityManager
    {
        return new EntityManager($this->connection, $this->registry, $scopes);
    }
}

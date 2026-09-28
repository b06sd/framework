<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Cache;

use Predis\Client;
use Trunk\Cache\Cache;
use Trunk\Cache\Store\RedisStore;
use Trunk\Cache\Store\Store;
use Trunk\Cache\Store\StoreFactory;
use Trunk\Contracts\Clock;
use Trunk\Foundation\Configuration;

/**
 * Runs the PSR-16 conformance suite against a real, local Redis server (a dedicated database index,
 * never 0, flushed before every test: this never touches a developer's own data), plus what only
 * matters for a real server: a second connection sees what the first wrote, and clear() removes only
 * this store's own keys. Skipped with a message when no server is reachable.
 *
 * Expiry is enforced by Redis's own real clock, not the injectable Clock the rest of the suite fakes
 * time with, so the two TTL tests are overridden here with a short real wait instead of FixedClock::advance().
 */
final class RedisStoreTest extends CacheBehaviourTestCase
{
    private const string HOST = '127.0.0.1';
    private const int PORT = 6379;
    private const int DATABASE = 15;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Client::class) || !@fsockopen(self::HOST, self::PORT, timeout: 0.2)) {
            self::markTestSkipped('No Redis server reachable at ' . self::HOST . ':' . self::PORT . '.');
        }

        $this->client()->flushdb();
    }

    public function test_entries_expire_after_an_integer_ttl(): void
    {
        // Arrange: Redis enforces this against real time, so this overrides the inherited FixedClock
        // version with a real, short wait instead.
        $cache = $this->cache();
        $cache->set('short', 'x', 1);

        // Act & Assert
        self::assertTrue($cache->has('short'));
        usleep(1_200_000);
        self::assertFalse($cache->has('short'));
    }

    public function test_a_date_interval_ttl_and_a_null_ttl_are_honoured(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->set('interval', 'x', 1);
        $cache->set('forever', 'y');

        // Act
        usleep(1_200_000);

        // Assert
        self::assertFalse($cache->has('interval'));
        self::assertTrue($cache->has('forever'));
    }

    public function test_a_value_written_by_one_connection_is_read_by_another(): void
    {
        // Arrange: two independent stores against the same server, standing in for two web servers
        // sharing one cache -- the exact bug a file or array store cannot fix.
        $one = new Cache(new RedisStore($this->client(), $this->clock), $this->clock);
        $two = new Cache(new RedisStore($this->client(), $this->clock), $this->clock);

        // Act
        $one->set('shared', ['from' => 'server-one']);

        // Assert
        self::assertSame(['from' => 'server-one'], $two->get('shared'));
    }

    public function test_clear_removes_only_this_stores_own_keys_never_the_whole_database(): void
    {
        // Arrange
        $client = $this->client();
        $client->set('other-app:untouched', 'x');
        $ours = new RedisStore($client, $this->clock, 'mine.');
        $ours->write('mine.a', 'value', null);

        // Act
        $ours->clear();

        // Assert
        self::assertNull($ours->read('mine.a'));
        self::assertSame('x', $client->get('other-app:untouched'));
    }

    public function test_the_store_factory_builds_a_working_redis_store_from_configuration(): void
    {
        // Arrange
        $factory = new StoreFactory($this->clock);
        $configuration = new Configuration(['cache' => ['redis' => ['host' => self::HOST, 'port' => self::PORT, 'database' => self::DATABASE]]]);

        // Act
        $store = $factory->make('redis', '', '', $configuration);
        $store->write('factory-key', 'ok', null);

        // Assert
        self::assertSame(['ok'], $store->read('factory-key'));
    }

    protected function store(Clock $clock): Store
    {
        return new RedisStore($this->client(), $clock);
    }

    private function client(): Client
    {
        return new Client(['scheme' => 'tcp', 'host' => self::HOST, 'port' => self::PORT, 'database' => self::DATABASE]);
    }
}

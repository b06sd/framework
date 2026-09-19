<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Cache;

use DateInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Trunk\Cache\Cache;
use Trunk\Cache\Exception\InvalidKeyException;
use Trunk\Cache\Store\Store;
use Trunk\Contracts\Clock;
use Trunk\Tests\Support\FixedClock;

/**
 * The PSR-16 behaviour every store must provide; each concrete test class supplies a store.
 */
abstract class CacheBehaviourTestCase extends TestCase
{
    protected FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
    }

    public function test_values_can_be_stored_read_checked_and_deleted(): void
    {
        // Arrange
        $cache = $this->cache();

        // Act
        $stored = $cache->set('greeting', 'hello');

        // Assert
        self::assertTrue($stored);
        self::assertTrue($cache->has('greeting'));
        self::assertSame('hello', $cache->get('greeting'));
        self::assertTrue($cache->delete('greeting'));
        self::assertFalse($cache->has('greeting'));
        self::assertSame('fallback', $cache->get('greeting', 'fallback'));
    }

    public function test_a_cached_null_is_a_hit_not_a_miss(): void
    {
        // Arrange
        $cache = $this->cache();

        // Act
        $cache->set('nothing', null);

        // Assert
        self::assertTrue($cache->has('nothing'));
        self::assertNull($cache->get('nothing', 'default'));
    }

    public function test_data_round_trips_with_its_types(): void
    {
        // Arrange
        $cache = $this->cache();
        $value = ['int' => 1, 'float' => 1.0, 'small' => 0.1, 'bool' => false, 'null' => null, 'text' => "caf\u{e9} \u{1F600}", 'list' => [1, 2, [3]], 'map' => ['a' => ['b' => 'c']]];

        // Act
        $cache->set('data', $value);
        $read = $cache->get('data');

        // Assert
        self::assertSame($value, $read);
    }

    public function test_only_data_can_be_cached(): void
    {
        // Arrange
        $cache = $this->cache();

        // Act
        $results = [
            $cache->set('object', new stdClass()),
            $cache->set('nested', ['ok' => 1, 'bad' => new stdClass()]),
            $cache->set('inf', \INF),
            $cache->set('nan', \NAN),
            $cache->set('binary', "\xB1\x31"),
            $cache->set('closure', static fn(): int => 1),
        ];

        // Assert
        self::assertSame([false, false, false, false, false, false], $results);
        self::assertFalse($cache->has('object'));
        self::assertFalse($cache->has('nested'));
    }

    public function test_entries_expire_after_an_integer_ttl(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->set('short', 'x', 60);

        // Act
        $this->clock->advance(59);
        $before = $cache->has('short');
        $this->clock->advance(1);
        $after = $cache->has('short');

        // Assert
        self::assertTrue($before);
        self::assertFalse($after);
    }

    public function test_a_date_interval_ttl_and_a_null_ttl_are_honoured(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->set('interval', 'x', new DateInterval('PT2M'));
        $cache->set('forever', 'y');

        // Act
        $this->clock->advance(119);
        $stillThere = $cache->has('interval');
        $this->clock->advance(2);
        $this->clock->advance(100_000_000);

        // Assert
        self::assertTrue($stillThere);
        self::assertFalse($cache->has('interval'));
        self::assertTrue($cache->has('forever'));
    }

    public function test_a_zero_or_negative_ttl_deletes_the_item(): void
    {
        // Arrange
        $cache = $this->cache();
        $cache->set('a', 1);
        $cache->set('b', 1);

        // Act
        $zero = $cache->set('a', 2, 0);
        $negative = $cache->set('b', 2, -5);

        // Assert
        self::assertTrue($zero);
        self::assertTrue($negative);
        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    public function test_multiple_operations_and_clear(): void
    {
        // Arrange
        $cache = $this->cache();

        // Act
        $cache->setMultiple(['one' => 1, 'two' => 2, 'three' => 3]);
        $some = $cache->getMultiple(['one', 'two', 'missing'], 'none');
        $cache->deleteMultiple(['one']);
        $generated = $cache->getMultiple((static function (): iterable {
            yield 'two';
            yield 'three';
        })());
        $cache->clear();

        // Assert
        self::assertSame(['one' => 1, 'two' => 2, 'missing' => 'none'], $some);
        self::assertSame(['two' => 2, 'three' => 3], $generated);
        self::assertFalse($cache->has('two'));
        self::assertFalse($cache->has('three'));
    }

    public function test_prefixes_keep_caches_sharing_a_store_apart(): void
    {
        // Arrange
        $store = $this->store($this->clock);
        $blog = $this->cache('blog.', $store);
        $shop = $this->cache('shop.', $store);

        // Act
        $blog->set('title', 'Blog');
        $shop->set('title', 'Shop');

        // Assert
        self::assertSame('Blog', $blog->get('title'));
        self::assertSame('Shop', $shop->get('title'));
    }

    public function test_remember_computes_a_missing_value_once(): void
    {
        // Arrange
        $cache = $this->cache();
        $calls = 0;
        $compute = static function () use (&$calls): string {
            ++$calls;

            return 'expensive';
        };

        // Act
        $first = $cache->remember('slow', 60, $compute);
        $second = $cache->remember('slow', 60, $compute);

        // Assert
        self::assertSame(['expensive', 'expensive', 1], [$first, $second, $calls]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'curly' => ['a{b}'];
        yield 'parens' => ['a(b)'];
        yield 'slash' => ['a/b'];
        yield 'backslash' => ['a\\b'];
        yield 'at' => ['a@b'];
        yield 'colon' => ['a:b'];
        yield 'space' => ['a b'];
        yield 'traversal' => ['../../etc/passwd'];
        yield 'newline' => ["a\nb"];
        yield 'nul' => ["a\0b"];
        yield 'unicode' => ["caf\u{e9}"];
        yield 'too long' => [str_repeat('a', 256)];
    }

    #[DataProvider('invalidKeys')]
    public function test_keys_outside_the_supported_alphabet_are_rejected_everywhere(string $key): void
    {
        // Arrange
        $cache = $this->cache();
        $rejected = 0;

        // Act
        foreach ([static fn() => $cache->get($key), static fn() => $cache->set($key, 1), static fn() => $cache->has($key), static fn() => $cache->delete($key), static fn() => $cache->getMultiple([$key])] as $attempt) {
            try {
                $attempt();
            } catch (InvalidKeyException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(5, $rejected);
    }

    abstract protected function store(Clock $clock): Store;

    protected function cache(string $prefix = '', ?Store $store = null): Cache
    {
        return new Cache($store ?? $this->store($this->clock), $this->clock, $prefix);
    }
}

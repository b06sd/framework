<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Orm;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Orm\Compiler\OrmArtifact;
use Trunk\Orm\Compiler\OrmCodeGenerator;
use Trunk\Orm\Exception\HydrationException;
use Trunk\Orm\Mapping\Convert;
use Trunk\Orm\Mapping\DevelopmentRegistry;
use Trunk\Orm\Mapping\MappingRegistry;
use Trunk\Orm\Mapping\MetadataFactory;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Fixtures\Orm\CustomerMap;
use Trunk\Tests\Fixtures\Orm\OrderMap;
use Trunk\Tests\Fixtures\Orm\ProfileMap;
use Trunk\Tests\Fixtures\Orm\Status;
use Trunk\Tests\Fixtures\Orm\TagMap;

final class ConvertAndMapperTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-orm-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function badValues(): iterable
    {
        yield 'int from text' => [static fn() => Convert::int('12abc', 'E', 'c')];
        yield 'int from float fraction' => [static fn() => Convert::int(1.5, 'E', 'c')];
        yield 'int from null' => [static fn() => Convert::int(null, 'E', 'c')];
        yield 'string from array' => [static fn() => Convert::string([], 'E', 'c')];
        yield 'float from text' => [static fn() => Convert::float('abc', 'E', 'c')];
        yield 'bool from 2' => [static fn() => Convert::bool(2, 'E', 'c')];
        yield 'bool from yes' => [static fn() => Convert::bool('yes', 'E', 'c')];
        yield 'date from garbage' => [static fn() => Convert::dateTime('not a date', 'E', 'c')];
        yield 'date from 2026-02-31' => [static fn() => Convert::dateTime('2026-02-31 00:00:00', 'E', 'c')];
        yield 'enum unknown case' => [static fn() => Convert::enum('deleted', Status::class, 'E', 'c')];
        yield 'json invalid' => [static fn() => Convert::json('{nope', 'E', 'c')];
        yield 'json scalar' => [static fn() => Convert::json('42', 'E', 'c')];
        yield 'json too deep' => [static fn() => Convert::json(str_repeat('[', 40) . str_repeat(']', 40), 'E', 'c')];
    }

    /**
     * @param callable(): mixed $convert
     */
    #[DataProvider('badValues')]
    public function test_invalid_database_values_are_rejected_and_never_echoed(callable $convert): void
    {
        // Arrange & Act
        try {
            $convert();
            self::fail('Expected a HydrationException.');
        } catch (HydrationException $e) {
            // Assert
            self::assertStringContainsString('column "c"', $e->getMessage());
            self::assertStringNotContainsString('12abc', $e->getMessage());
            self::assertStringNotContainsString('garbage', $e->getMessage());
        }
    }

    public function test_accepted_representations_from_the_three_drivers(): void
    {
        // Arrange & Act & Assert
        self::assertSame(7, Convert::int('7', 'E', 'c'));
        self::assertSame(7, Convert::int(7.0, 'E', 'c'));
        self::assertSame(1.5, Convert::float('1.5', 'E', 'c'));
        self::assertSame(2.0, Convert::float(2, 'E', 'c'));
        self::assertTrue(Convert::bool('t', 'E', 'c'));
        self::assertFalse(Convert::bool(0, 'E', 'c'));
        self::assertSame('2026-03-04 05:06:07', Convert::dateTime('2026-03-04 05:06:07', 'E', 'c')->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-04 00:00:00', Convert::dateTime('2026-03-04', 'E', 'c')->format('Y-m-d H:i:s'));
        self::assertSame(Status::Blocked, Convert::enum('blocked', Status::class, 'E', 'c'));
        self::assertSame(['a' => 1], Convert::json('{"a":1}', 'E', 'c'));
    }

    public function test_dates_are_written_as_utc_whatever_their_timezone(): void
    {
        // Arrange
        $local = new DateTimeImmutable('2026-06-01 12:00:00 Europe/Berlin');

        // Act & Assert
        self::assertSame('2026-06-01 10:00:00', Convert::dateTimeToDb($local));
        self::assertNull(Convert::dateTimeToDb(null));
    }

    public function test_generated_and_interpreted_mappers_behave_identically(): void
    {
        // Arrange
        $compiled = $this->compiled()->mapper(Customer::class);
        $interpreted = new DevelopmentRegistry([CustomerMap::class, OrderMap::class, ProfileMap::class, TagMap::class])->mapper(Customer::class);

        // Act
        $a = $compiled->hydrate(self::row());
        $b = $interpreted->hydrate(self::row());

        // Assert
        self::assertEquals($a, $b);
        self::assertInstanceOf(Customer::class, $a);
        self::assertSame(5, $a->id);
        self::assertSame(Status::Blocked, $a->status);
        self::assertSame(['a' => [1, 2]], $a->settings);
        self::assertSame($compiled->extract($a), $interpreted->extract($b));
        self::assertSame('2026-03-04 05:06:07', $compiled->extract($a)['created_at']);
        self::assertSame('blocked', $compiled->extract($a)['status']);
        self::assertSame('{"a":[1,2]}', $compiled->extract($a)['settings']);
    }

    public function test_both_mappers_leave_a_hidden_column_at_its_default_when_the_row_has_none(): void
    {
        // Arrange
        $row = self::row();
        unset($row['password_hash']);
        $with = self::row();
        $with['password_hash'] = 'loaded';

        foreach ([$this->compiled(), new DevelopmentRegistry([CustomerMap::class, OrderMap::class, ProfileMap::class, TagMap::class])] as $registry) {
            // Act
            $without = $registry->mapper(Customer::class)->hydrate($row);
            $loaded = $registry->mapper(Customer::class)->hydrate($with);

            // Assert
            self::assertInstanceOf(Customer::class, $without);
            self::assertInstanceOf(Customer::class, $loaded);
            self::assertSame('', $without->passwordHash);
            self::assertSame('loaded', $loaded->passwordHash);
        }
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function corruptRows(): iterable
    {
        yield 'null in a non-null column' => ['name', null];
        yield 'bad enum' => ['status', 'archived'];
        yield 'bad json' => ['settings', '{'];
        yield 'bad date' => ['created_at', 'yesterday-ish'];
        yield 'bad int' => ['version', 'x'];
        yield 'missing column' => ['balance', null];
    }

    #[DataProvider('corruptRows')]
    public function test_both_mappers_reject_corrupt_rows_with_the_same_message(string $column, mixed $value): void
    {
        // Arrange
        $row = [...self::row(), $column => $value];
        $messages = [];

        foreach ([$this->compiled(), new DevelopmentRegistry([CustomerMap::class, OrderMap::class, ProfileMap::class, TagMap::class])] as $registry) {
            // Act
            try {
                $registry->mapper(Customer::class)->hydrate($row);
                self::fail('Expected a HydrationException.');
            } catch (HydrationException $e) {
                $messages[] = $e->getMessage();
            }
        }

        // Assert
        self::assertSame($messages[0], $messages[1]);
        self::assertStringContainsString('column "' . $column . '"', $messages[0]);
    }

    public function test_the_generated_file_is_deterministic_and_has_no_dynamic_code(): void
    {
        // Arrange
        $metadata = new MetadataFactory()->build([new CustomerMap(), new OrderMap(), new ProfileMap(), new TagMap()]);
        $generator = new OrmCodeGenerator();

        // Act
        $source = $generator->generate($metadata);

        // Assert
        self::assertSame($source, $generator->generate($metadata));
        self::assertStringNotContainsString('eval(', $source);
        self::assertStringNotContainsString('unserialize', $source);
        self::assertStringNotContainsString('Reflection', $source);
        self::assertStringContainsString('static fn(array $r): \\Trunk\\Tests\\Fixtures\\Orm\\Customer => new \\Trunk\\Tests\\Fixtures\\Orm\\Customer(', $source);
        self::assertSame(0, substr_count($source, 'SELECT'));
    }

    private function compiled(): MappingRegistry
    {
        $source = new OrmCodeGenerator()->generate(new MetadataFactory()->build([new CustomerMap(), new OrderMap(), new ProfileMap(), new TagMap()]));
        file_put_contents($this->directory . '/orm.php', $source);

        return OrmArtifact::load($this->directory . '/orm.php');
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(): array
    {
        return ['id' => '5', 'name' => 'Ada', 'email' => 'ada@example.com', 'password_hash' => 'h', 'status' => 'blocked', 'nickname' => null, 'active' => 1, 'balance' => '12.5', 'settings' => '{"a":[1,2]}', 'created_at' => '2026-03-04 05:06:07', 'deleted_at' => null, 'version' => 3];
    }
}

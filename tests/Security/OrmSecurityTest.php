<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use InvalidArgumentException;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stdClass;
use Throwable;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Orm\Compiler\OrmCodeGenerator;
use Trunk\Orm\Exception\InvalidFilter;
use Trunk\Orm\Exception\UnknownProperty;
use Trunk\Orm\Mapping\ColumnMetadata;
use Trunk\Orm\Mapping\EntityMetadata;
use Trunk\Orm\Mapping\Type;
use Trunk\Orm\UnitOfWork\EntityManager;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Support\OrmHarness;

/**
 * Hostile input must stay data, and untrusted field names must never reach SQL.
 */
final class OrmSecurityTest extends TestCase
{
    private OrmHarness $orm;

    private EntityManager $manager;

    protected function setUp(): void
    {
        $this->orm = new OrmHarness();
        $this->manager = $this->orm->manager();
        $this->manager->persist(new Customer(name: 'Ada', email: 'ada@example.com', passwordHash: 'super-secret-hash'));
        $this->manager->persist(new Customer(name: 'Grace', email: 'grace@example.com', passwordHash: 'another-secret'));
        $this->manager->flush();
        $this->manager->clear();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield 'drop table' => ["'); DROP TABLE customers; --"];
        yield 'or true' => ["' OR '1'='1"];
        yield 'union' => ["' UNION SELECT password_hash, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1 FROM customers --"];
        yield 'backslash' => ["\\'; DELETE FROM customers; --"];
        yield 'like wildcard' => ['%'];
        yield 'null byte' => ["x\0y"];
    }

    #[DataProvider('payloads')]
    public function test_payloads_are_stored_and_found_as_plain_data_through_the_orm(string $payload): void
    {
        // Arrange
        $customers = $this->manager->repository(Customer::class);
        $this->manager->persist(new Customer(name: $payload, email: md5($payload) . '@x.dev'));
        $this->manager->flush();
        $this->manager->clear();

        // Act
        $found = $customers->query()->where('name', $payload)->get();
        $viaFilter = $customers->query()->filter(['name' => $payload])->get();
        $viaIn = $customers->query()->filter(['name' => [$payload, 'nobody']])->get();

        // Assert
        self::assertCount(1, $found);
        self::assertSame($payload, $found[0]->name);
        self::assertCount(1, $viaFilter);
        self::assertCount(1, $viaIn);
        self::assertSame(3, $customers->query()->count());
    }

    #[DataProvider('payloads')]
    public function test_second_order_payloads_stay_data_when_reused_in_a_later_query(string $payload): void
    {
        // Arrange
        $customers = $this->manager->repository(Customer::class);
        $this->manager->persist(new Customer(name: $payload, email: md5($payload) . '@x.dev'));
        $this->manager->flush();
        $stored = $customers->query()->where('email', md5($payload) . '@x.dev')->first();

        // Act
        $again = $customers->query()->where('name', $stored instanceof Customer ? $stored->name : '')->count();

        // Assert
        self::assertSame(1, $again);
        self::assertSame(3, $customers->query()->count());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileNames(): iterable
    {
        yield 'sql in name' => ['name; DROP TABLE customers'];
        yield 'quote' => ['name"'];
        yield 'raw column' => ['password_hash'];
        yield 'table qualified' => ['customers.name'];
        yield 'unmapped' => ['secret'];
        yield 'empty' => [''];
        yield 'comment' => ['name--'];
        yield 'wildcard' => ['*'];
    }

    #[DataProvider('hostileNames')]
    public function test_untrusted_field_names_never_become_columns(string $name): void
    {
        // Arrange
        $query = $this->manager->repository(Customer::class)->query();
        $rejected = 0;

        // Act
        foreach ([
            static fn() => $query->filter([$name => 'x']),
            static fn() => $query->sortBy($name),
            static fn() => $query->sortBy('-' . $name),
            static fn() => $query->where($name, 'x'),
            static fn() => $query->orderBy($name),
            static fn() => $query->with($name),
        ] as $attempt) {
            try {
                $attempt();
            } catch (UnknownProperty|InvalidFilter|InvalidQueryException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(6, $rejected);
    }

    public function test_request_filters_and_sorts_only_reach_properties_the_map_allows(): void
    {
        // Arrange
        $query = $this->manager->repository(Customer::class)->query();
        $blocked = 0;

        // Act
        foreach (['passwordHash', 'version', 'deletedAt', 'settings', 'id'] as $property) {
            try {
                $query->filter([$property => 'x']);
            } catch (UnknownProperty) {
                ++$blocked;
            }
        }

        foreach (['passwordHash', 'id', 'status'] as $property) {
            try {
                $query->sortBy($property);
            } catch (UnknownProperty) {
                ++$blocked;
            }
        }

        // Assert
        self::assertSame(8, $blocked);
        self::assertCount(1, $query->filter(['email' => 'ada@example.com'])->sortBy('-name,email')->get());
    }

    public function test_filter_values_are_type_checked_and_bounded(): void
    {
        // Arrange
        $query = $this->manager->repository(Customer::class)->query();
        $bad = [
            ['status' => 'archived'],
            ['status' => ['active', 'x']],
            ['active' => 'maybe'],
            ['name' => []],
            ['name' => ['a' => 'b']],
            ['name' => array_fill(0, 101, 'x')],
            ['name' => new stdClass()],
            ['name' => [['nested']]],
        ];
        $rejected = 0;

        // Act
        foreach ($bad as $filter) {
            try {
                $query->filter($filter);
            } catch (InvalidFilter|UnknownProperty) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(\count($bad), $rejected);
        $this->expectException(InvalidFilter::class);
        $query->sortBy('name,email,name,email');
    }

    public function test_mass_assignment_is_impossible_only_allowlisted_fields_are_accepted(): void
    {
        // Arrange
        $customers = $this->manager->repository(Customer::class);
        $attempts = [
            [['name' => 'x', 'passwordHash' => 'evil'], ['name']],
            [['name' => 'x', 'id' => 99], ['name', 'id']],
            [['name' => 'x', 'version' => 9], ['name', 'version']],
            [['name' => 'x', 'deletedAt' => '2026-01-01'], ['name', 'deletedAt']],
            [['name' => 'x'], ['name', 'doesNotExist']],
        ];
        $rejected = 0;

        // Act
        foreach ($attempts as [$input, $allow]) {
            try {
                $customers->input($input, $allow);
            } catch (InvalidFilter|UnknownProperty) {
                ++$rejected;
            }
        }
        $typed = $customers->input(['name' => 'Ada', 'status' => 'blocked', 'balance' => '3.5', 'settings' => ['a' => 1]], ['name', 'status', 'balance', 'settings']);

        // Assert
        self::assertSame(\count($attempts), $rejected);
        self::assertSame(['name' => 'Ada', 'status' => \Trunk\Tests\Fixtures\Orm\Status::Blocked, 'balance' => 3.5, 'settings' => ['a' => 1]], $typed);
    }

    public function test_hidden_and_unmapped_properties_never_appear_in_serialisation(): void
    {
        // Arrange
        $ada = $this->manager->repository(Customer::class)->findOrFail(1);

        // Act
        $array = $this->manager->toArray($ada);
        $json = json_encode($array, JSON_THROW_ON_ERROR);

        // Assert
        self::assertArrayNotHasKey('passwordHash', $array);
        self::assertStringNotContainsString('super-secret-hash', $json);
        self::assertSame('active', $array['status']);
        self::assertSame('Ada', $array['name']);
        self::assertIsString($array['createdAt']);
    }

    public function test_orm_errors_never_contain_row_values(): void
    {
        // Arrange
        $this->orm->connection->execute("UPDATE customers SET status = 'top-secret-value' WHERE id = 1");
        $messages = [];

        // Act
        try {
            $this->manager->repository(Customer::class)->findOrFail(1);
        } catch (Throwable $e) {
            $messages[] = $e->getMessage();
        }

        try {
            $this->manager->persist(new Customer(name: 'Dup', email: 'grace@example.com', passwordHash: 'leak-me'));
            $this->manager->flush();
        } catch (Throwable $e) {
            $messages[] = $e->getMessage();
        }

        // Assert
        self::assertCount(2, $messages);
        self::assertStringNotContainsString('top-secret-value', implode(' ', $messages));
        self::assertStringNotContainsString('grace@example.com', implode(' ', $messages));
        self::assertStringNotContainsString('leak-me', implode(' ', $messages));
        self::assertStringNotContainsString('leak-me', (string) json_encode($this->orm->log->entries()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function codeInjection(): iterable
    {
        yield 'statement' => ["id; system('id')"];
        yield 'quote' => ["id'] => 1, 'x"];
        yield 'space' => ['my property'];
        yield 'dollar' => ['$e'];
    }

    #[DataProvider('codeInjection')]
    public function test_the_code_generator_refuses_property_names_that_could_break_out_of_generated_code(string $property): void
    {
        // Arrange
        $column = new ColumnMetadata($property, 'title', Type::String, false, false, false, false, null);
        $entity = new EntityMetadata(Customer::class, 'things', $property, false, [$property => $column], [], null, null, [], ['title']);

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        new OrmCodeGenerator()->generate([Customer::class => $entity]);
    }

    public function test_the_orm_package_has_no_dynamic_code_execution_or_unserialization(): void
    {
        // Arrange
        $forbidden = ['eval(', 'unserialize(', 'shell_exec(', 'exec(', 'system(', 'passthru(', 'proc_open(', 'setAccessible(', 'ReflectionProperty', 'create_function', 'assert('];
        $hits = [];

        // Act
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../packages/orm/src')) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $tokens = PhpToken::tokenize((string) file_get_contents($file->getPathname()));
            $code = implode('', array_map(static fn(PhpToken $t): string => $t->is([T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) ? '' : $t->text, $tokens));

            foreach ($forbidden as $needle) {
                if (str_contains($code, $needle) && !($needle === 'exec(' && !preg_match('/(?<![>:\w])exec\(/', $code))) {
                    $hits[] = $file->getFilename() . ': ' . $needle;
                }
            }
        }

        // Assert
        self::assertSame([], $hits);
    }

    public function test_global_scopes_cannot_be_dropped_by_a_forgotten_registration(): void
    {
        // Arrange
        $orm = new OrmHarness(new \Trunk\Orm\Mapping\DevelopmentRegistry([\Trunk\Tests\Fixtures\Orm\CustomerMap::class, \Trunk\Tests\Fixtures\Orm\ScopedOrderMap::class, \Trunk\Tests\Fixtures\Orm\ProfileMap::class, \Trunk\Tests\Fixtures\Orm\TagMap::class]));

        // Act & Assert
        $this->expectException(\Trunk\Orm\Exception\OrmException::class);
        $this->expectExceptionMessage('no such scope service is registered');
        $orm->manager()->repository(\Trunk\Tests\Fixtures\Orm\Order::class)->all();
    }
}

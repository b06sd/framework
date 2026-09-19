<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Auth\Session\ArraySessionStore;
use Trunk\Auth\Session\DatabaseSessionStore;
use Trunk\Auth\Session\FileSessionStore;
use Trunk\Auth\Session\SessionCodec;
use Trunk\Auth\Session\SessionId;
use Trunk\Auth\Session\SessionRecord;
use Trunk\Auth\Session\SessionStore;
use Trunk\Support\Directory;
use Trunk\Tests\Support\AuthHarness;

/**
 * One behavioural contract, run against every store: array, file, and the database store on SQLite
 * (always) and MySQL / PostgreSQL (when configured).
 */
final class SessionStoreTest extends TestCase
{
    private ?AuthHarness $auth = null;

    private ?string $directory = null;

    protected function tearDown(): void
    {
        $this->auth?->cleanUp();

        if ($this->directory !== null) {
            new Directory()->remove($this->directory);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'array' => ['array'];
        yield 'file' => ['file'];
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('stores')]
    public function test_a_record_round_trips_and_a_missing_one_is_null(string $kind): void
    {
        // Arrange
        $store = $this->store($kind);
        $hash = SessionId::hash(SessionId::generate());
        $record = new SessionRecord(['user' => '7', 'nested' => ['a' => [1, 2.5, true, null, 'é']]], 1_800_000_000, 1_800_000_100);

        // Act
        $store->write($hash, $record);

        // Assert
        self::assertEquals($record, $store->read($hash));
        self::assertNull($store->read(SessionId::hash('another')));
    }

    #[DataProvider('stores')]
    public function test_writing_again_replaces_the_record_and_identical_writes_are_harmless(string $kind): void
    {
        // Arrange
        $store = $this->store($kind);
        $hash = SessionId::hash(SessionId::generate());
        $first = new SessionRecord(['n' => 1], 100, 200);

        // Act
        $store->write($hash, $first);
        $store->write($hash, $first);
        $store->write($hash, new SessionRecord(['n' => 2], 100, 300));

        // Assert
        self::assertSame(['n' => 2], $store->read($hash)?->data);
        self::assertSame(300, $store->read($hash)->lastActivity);
    }

    #[DataProvider('stores')]
    public function test_delete_and_prune_remove_only_what_they_should(string $kind): void
    {
        // Arrange
        $store = $this->store($kind);
        [$old, $recent, $gone] = [SessionId::hash('old'), SessionId::hash('recent'), SessionId::hash('gone')];
        $store->write($old, new SessionRecord([], 100, 1_000));
        $store->write($recent, new SessionRecord([], 100, 5_000));
        $store->write($gone, new SessionRecord([], 100, 5_000));

        // Act
        $store->delete($gone);
        $store->delete(SessionId::hash('never existed'));
        $removed = $store->prune(2_000);

        // Assert
        self::assertSame(1, $removed);
        self::assertNull($store->read($old));
        self::assertNotNull($store->read($recent));
        self::assertNull($store->read($gone));
    }

    #[DataProvider('stores')]
    public function test_data_that_is_not_json_safe_or_is_too_large_is_refused_not_stored(string $kind): void
    {
        // Arrange
        $store = $this->store($kind);
        $hash = SessionId::hash('big');

        // Act & Assert
        try {
            $store->write($hash, new SessionRecord(['blob' => str_repeat('x', SessionCodec::MAX_BYTES)], 1, 1));
            self::fail('an oversized session must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('larger than', $e->getMessage());
        }

        try {
            $store->write($hash, new SessionRecord(['bad' => "\xB1\x31"], 1, 1));
            self::fail('invalid UTF-8 must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('only strings, numbers', $e->getMessage());
        }

        self::assertNull($store->read($hash));
    }

    public function test_the_database_store_never_holds_the_session_id_only_its_hash(): void
    {
        // Arrange
        $auth = $this->auth = AuthHarness::for('sqlite') ?? self::fail();
        $store = new DatabaseSessionStore($auth->connection, 'trunk_at_sessions');
        $id = SessionId::generate();

        // Act
        $store->write(SessionId::hash($id), new SessionRecord(['k' => 'v'], 1, 1));
        $rows = $auth->connection->table('trunk_at_sessions')->get();

        // Assert
        self::assertCount(1, $rows);
        self::assertSame(SessionId::hash($id), $rows[0]['id_hash']);
        self::assertStringNotContainsString($id, json_encode($rows, \JSON_THROW_ON_ERROR));
    }

    public function test_a_damaged_stored_session_is_no_session_and_serialized_php_is_never_read(): void
    {
        // Arrange
        $auth = $this->auth = AuthHarness::for('sqlite') ?? self::fail();
        $store = new DatabaseSessionStore($auth->connection, 'trunk_at_sessions');
        $hash = SessionId::hash('damaged');
        $store->write($hash, new SessionRecord([], 1, 1));

        // Act & Assert
        foreach (['', '{', 'not json', 'O:8:"stdClass":0:{}', '{"d":"x","c":1,"a":1}', '{"d":{},"c":"1","a":1}', '[]', 'null'] as $payload) {
            $auth->connection->table('trunk_at_sessions')->where('id_hash', '=', $hash)->update(['payload' => $payload]);
            self::assertNull($store->read($hash), $payload);
        }
    }

    public function test_the_file_store_is_private_and_only_ever_touches_files_named_by_a_hash(): void
    {
        // Arrange
        $this->directory = sys_get_temp_dir() . '/trunk-sessions-' . bin2hex(random_bytes(4));
        $store = new FileSessionStore($this->directory . '/nested');
        $hash = SessionId::hash('private');

        // Act
        $store->write($hash, new SessionRecord(['a' => 1], 1, 1));

        // Assert
        self::assertSame('700', substr(\sprintf('%o', fileperms($this->directory . '/nested')), -3));
        self::assertSame('600', substr(\sprintf('%o', fileperms($this->directory . '/nested/' . $hash . '.session')), -3));

        $refused = 0;

        foreach (['../../etc/passwd', '', 'abc', str_repeat('g', 64), $hash . '/../x'] as $bad) {
            try {
                $store->read($bad);
            } catch (InvalidArgumentException) {
                ++$refused;
            }
        }

        self::assertSame(5, $refused, 'every non-hash key is refused');
    }

    public function test_session_ids_are_random_url_safe_and_validated_strictly(): void
    {
        // Act
        $ids = array_map(static fn() => SessionId::generate(), range(1, 50));

        // Assert
        self::assertCount(50, array_unique($ids));
        foreach ($ids as $id) {
            self::assertTrue(SessionId::isValid($id));
            self::assertSame(43, \strlen($id));
        }

        foreach (['', 'short', str_repeat('a', 44), str_repeat('a', 42) . '=', str_repeat('a', 42) . '/', str_repeat("a", 42) . "\n", str_repeat('é', 43)] as $bad) {
            self::assertFalse(SessionId::isValid($bad), $bad);
        }
    }

    private function store(string $kind): SessionStore
    {
        if ($kind === 'array') {
            return new ArraySessionStore();
        }

        if ($kind === 'file') {
            $this->directory = sys_get_temp_dir() . '/trunk-sessions-' . bin2hex(random_bytes(4));

            return new FileSessionStore($this->directory);
        }

        $auth = $this->auth = AuthHarness::for($kind) ?? self::markTestSkipped($kind . ' is not configured.');

        return new DatabaseSessionStore($auth->connection, 'trunk_at_sessions');
    }
}

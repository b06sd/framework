<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Auth\Jwt;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Auth\Jwt\HttpKeySource;

/**
 * The key set fetch, against a real HTTP server on this machine (plain http is allowed only on loopback).
 */
final class HttpKeySourceTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $address = '';

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        self::$address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $server = proc_open([\PHP_BINARY, '-S', self::$address, \dirname(__DIR__, 3) . '/Support/jwks_server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($server);
        self::$server = $server;

        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', (int) substr(strrchr(self::$address, ':') ?: ':0', 1)) === false; ++$i) {
            usleep(20_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function test_it_fetches_the_key_set(): void
    {
        // Act
        $json = new HttpKeySource()->fetch('http://' . self::$address . '/.well-known/jwks.json');

        // Assert
        self::assertSame('{"keys":[]}', $json);
    }

    public function test_a_status_other_than_200_is_an_error(): void
    {
        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('answered HTTP 404');
        new HttpKeySource()->fetch('http://' . self::$address . '/missing');
    }

    public function test_a_redirect_is_not_followed(): void
    {
        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('answered HTTP 302');
        new HttpKeySource()->fetch('http://' . self::$address . '/redirect');
    }

    public function test_a_body_over_one_megabyte_is_refused(): void
    {
        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('larger than 1 MB');
        new HttpKeySource()->fetch('http://' . self::$address . '/big');
    }

    public function test_plain_http_to_another_host_is_never_attempted(): void
    {
        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('it must be https');
        new HttpKeySource()->fetch('http://id.example.com/.well-known/jwks.json');
    }
}

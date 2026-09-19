<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Server;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Throwable;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\Http\Server\TrustedProxies;

/**
 * Client-supplied forwarding headers must not change scheme, host or client identity, and hostile
 * $_FILES structures must fail cleanly, never with a TypeError or an unbounded loop.
 */
final class UntrustedInputTest extends TestCase
{
    public function test_forwarded_headers_never_change_the_scheme_host_or_port(): void
    {
        // Arrange
        $server = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'app.test',
            'REQUEST_URI' => '/x',
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_X_FORWARDED_PORT' => '8443',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
            'HTTP_FORWARDED' => 'for=10.0.0.1;host=evil.example;proto=https',
            'HTTP_X_REAL_IP' => '10.0.0.1',
        ];

        // Act
        $request = new ServerRequestCreator()->fromArrays($server);

        // Assert
        self::assertSame('http://app.test/x', (string) $request->getUri());
        self::assertSame('203.0.113.9', $request->getServerParams()['REMOTE_ADDR']);
    }

    public function test_the_forwarded_header_of_rfc_7239_is_never_read_even_from_a_trusted_proxy(): void
    {
        // Arrange
        $server = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'app.test', 'REQUEST_URI' => '/x', 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_FORWARDED' => 'for=1.2.3.4;host=evil.example;proto=https'];

        // Act
        $request = new ServerRequestCreator(proxies: new TrustedProxies(['10.0.0.0/8']))->fromArrays($server);

        // Assert
        self::assertSame('http://app.test/x', (string) $request->getUri());
        self::assertSame('10.0.0.5', $request->getAttribute('client_ip'));
    }

    public function test_hostile_upload_structures_fail_cleanly_or_are_accepted_never_crash(): void
    {
        // Arrange
        $server = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'a.test', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x'];
        $pieces = [null, true, 'x', 7, -1, 1.5, [], ['a' => ['b' => 'c']], ['name' => 'n'], ['name' => ['n'], 'tmp_name' => 'x', 'error' => 0, 'size' => 1, 'type' => 't'], ['name' => 'f', 'tmp_name' => '/nonexistent', 'error' => \UPLOAD_ERR_OK, 'size' => \PHP_INT_MAX, 'type' => 'a/b'], ['name' => 'f', 'tmp_name' => '', 'error' => 99, 'size' => -5, 'type' => "a\r\nb"]];

        // Act
        for ($seed = 1; $seed <= 400; ++$seed) {
            mt_srand($seed);
            $files = [];
            for ($i = 0, $n = mt_rand(1, 4); $i < $n; ++$i) {
                $files['f' . $i] = $pieces[mt_rand(0, \count($pieces) - 1)];

                if (mt_rand(0, 3) === 0) {
                    $files['f' . $i] = ['x' => $files['f' . $i], 'y' => $pieces[mt_rand(0, \count($pieces) - 1)]];
                }
            }

            try {
                new ServerRequestCreator()->fromArrays($server, files: $files);
            } catch (HttpException|InvalidArgumentException) {
                // A clean, typed rejection is the expected outcome for malformed input.
            } catch (Throwable $e) {
                self::fail(\sprintf('seed %d: %s: %s', $seed, $e::class, $e->getMessage()));
            }
        }

        // Assert
        self::addToAssertionCount(1);
    }
}

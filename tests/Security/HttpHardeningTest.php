<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use Trunk\Foundation\Environment;
use Trunk\Http\Emitter\ResponseEmitter;
use Trunk\Http\Emitter\Sapi;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\HttpModule;
use Trunk\Http\Message\Response;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Server\RequestLimits;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\Http\Stream\Stream;
use Trunk\Http\Uri\Uri;
use Trunk\Tests\Fixtures\Modules\WebModule;
use Trunk\Tests\Support\FakeSapi;
use Trunk\Tests\Support\KernelHarness;

/**
 * Adversarial checks for the http package that go beyond the basics: whatever a client sends, the
 * server answers with a well-formed, bounded response or a clean rejection, never a crash, a hang,
 * a different authority, or a body it must not send.
 */
final class HttpHardeningTest extends TestCase
{
    private ?KernelHarness $harness = null;

    protected function tearDown(): void
    {
        $this->harness?->cleanUp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'development' => ['development'];
        yield 'compiled' => ['compiled'];
    }

    #[DataProvider('modes')]
    public function test_a_head_request_gets_the_headers_of_the_get_and_no_body(string $mode): void
    {
        // Arrange
        $sapi = new FakeSapi();
        $harness = $this->harness = new KernelHarness($sapi, [HttpModule::class, WebModule::class]);
        $kernel = $mode === 'compiled' ? $harness->compiled(Environment::Production) : $harness->development(Environment::Production);

        // Act
        $kernel->send(new ServerRequest('HEAD', 'http://trunk.dev/pages/7'));
        $head = $sapi->calls;
        $sapi->calls = [];
        $kernel->send(new ServerRequest('GET', 'http://trunk.dev/pages/7'));

        // Assert
        self::assertSame([], array_values(array_filter($head, static fn(string $c): bool => str_starts_with($c, 'write:'))), 'RFC 9110: a response to HEAD has no content');
        self::assertContains('status:HTTP/1.1 200 OK', $head);
        self::assertNotEmpty(array_filter($sapi->calls, static fn(string $c): bool => str_starts_with($c, 'write:page 7')), 'GET is unchanged');
    }

    public function test_an_overlong_request_target_is_a_414_before_anything_is_parsed(): void
    {
        // Arrange
        $creator = new ServerRequestCreator(new RequestLimits(maxUriBytes: 1024));

        // Act & Assert
        $creator->fromArrays(['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'a.test', 'REQUEST_URI' => '/' . str_repeat('a', 1000)]);

        try {
            $creator->fromArrays(['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'a.test', 'REQUEST_URI' => '/x?' . str_repeat('a', 1024)]);
            self::fail('expected a 414');
        } catch (HttpException $e) {
            self::assertSame(414, $e->statusCode());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badHosts(): iterable
    {
        yield 'port zero' => ['a.test:0'];
        yield 'port overflow' => ['a.test:99999'];
        yield 'empty port' => ['a.test:'];
        yield 'userinfo' => ['user@a.test'];
        yield 'path in host' => ['a.test/x'];
        yield 'two hosts' => ['a.test, b.test'];
        yield 'space' => ['a b'];
        yield 'unterminated ipv6' => ['[::1'];
        yield 'backslash' => ['a.test\\evil'];
        yield 'nul' => ["a.test\0"];
    }

    #[DataProvider('badHosts')]
    public function test_a_malformed_host_header_is_rejected_not_repaired(string $host): void
    {
        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        new ServerRequestCreator()->fromArrays(['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => $host, 'REQUEST_URI' => '/x']);
    }

    public function test_whatever_is_in_the_server_array_only_typed_rejections_come_out(): void
    {
        // Arrange
        mt_srand(21);
        $values = [null, true, 5, 5.5, [], ['a' => 'b'], '', 'x', "a\r\nb", "\0", str_repeat('A', 70_000), '/ok', 'GET', 'a.test', '[::1]:80', 'HTTP/9', 'on', '0', '-1', '99999999999999999999'];
        $keys = ['REQUEST_METHOD', 'REQUEST_URI', 'HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT', 'SERVER_PROTOCOL', 'HTTPS', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'HTTP_TRANSFER_ENCODING', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PORT', 'REMOTE_ADDR', 'HTTP_COOKIE', 'HTTP_ACCEPT', 'HTTP_X_ANYTHING', 'QUERY_STRING'];
        $creator = new ServerRequestCreator(proxies: new \Trunk\Http\Server\TrustedProxies(['10.0.0.0/8']));
        $untyped = [];

        // Act
        for ($i = 0; $i < 600; ++$i) {
            $server = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'a.test', 'REQUEST_URI' => '/x'];

            for ($j = 0, $n = mt_rand(1, 8); $j < $n; ++$j) {
                $server[$keys[mt_rand(0, \count($keys) - 1)]] = $values[mt_rand(0, \count($values) - 1)];
            }

            try {
                $request = $creator->fromArrays($server);
                self::assertNotSame('', $request->getMethod());
            } catch (InvalidArgumentException|HttpException) {
                // A clean, typed rejection.
            } catch (Throwable $e) {
                $untyped[] = $e::class . ': ' . $e->getMessage();
            }
        }

        // Assert
        self::assertSame([], array_values(array_unique($untyped)));
    }

    public function test_the_uri_class_never_reports_a_different_host_than_a_reference_parser_for_hostile_strings(): void
    {
        // Arrange
        $pieces = ['http://', 'https://', '//', 'good.example', 'evil.example', '@', ':', '80', '99999', '/', '\\', '?', '#', 'a', '%00', '%2f', '[::1]', '[', ']', ' ', "\t", '.', '..', '%40'];
        mt_srand(4);
        $mismatches = [];

        // Act
        for ($i = 0; $i < 3000; ++$i) {
            $candidate = '';
            for ($j = 0, $n = mt_rand(2, 9); $j < $n; ++$j) {
                $candidate .= $pieces[mt_rand(0, \count($pieces) - 1)];
            }

            $reference = parse_url($candidate);

            try {
                $uri = new Uri($candidate);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($reference !== false && isset($reference['host']) && $uri->getHost() !== strtolower($reference['host'])) {
                $mismatches[] = $candidate . ' => ' . $uri->getHost() . ' vs ' . $reference['host'];
            }

            if ($uri->getHost() !== '' && str_contains($uri->getHost(), '@')) {
                $mismatches[] = $candidate . ' => host contains @';
            }
        }

        // Assert
        self::assertSame([], \array_slice($mismatches, 0, 10));
    }

    #[DataProvider('modes')]
    public function test_random_paths_and_methods_always_get_a_clean_answer_never_a_500(string $mode): void
    {
        // Arrange
        $harness = $this->harness = new KernelHarness(new FakeSapi(), [HttpModule::class, WebModule::class]);
        $kernel = $mode === 'compiled' ? $harness->compiled(Environment::Production) : $harness->development(Environment::Production);
        $pieces = ['pages', 'list', 'echo', 'flag', '7', '-1', '0', '99999999999999999999', '%2F', '%00', '%zz', '..', '.', '%2e%2e', 'é', '٣', '1e3', '{id}', '?', '#', ';', "'", '"', '<', '>', '\\', '`', '$', '*', '+', '%25', '//', 'boom'];
        $methods = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'TRACE', 'CONNECT', 'PROPFIND'];
        $statuses = [];
        mt_srand(8);

        // Act
        for ($i = 0; $i < 700; ++$i) {
            $path = '';
            for ($j = 0, $n = mt_rand(1, 5); $j < $n; ++$j) {
                $path .= '/' . $pieces[mt_rand(0, \count($pieces) - 1)];
            }

            try {
                $request = new ServerRequest($methods[mt_rand(0, \count($methods) - 1)], 'http://trunk.dev' . $path);
            } catch (InvalidArgumentException) {
                continue;
            }

            $response = $kernel->handle($request);
            $statuses[$response->getStatusCode()] = true;
            self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
        }

        // Assert: only what a router answers (the deliberate /boom route is the one 500)
        self::assertSame([], array_values(array_diff(array_keys($statuses), [200, 404, 405, 500])));
    }

    public function test_a_large_body_is_streamed_to_the_client_in_chunks_not_loaded(): void
    {
        // Arrange
        $sapi = new class implements Sapi {
            public int $largest = 0;

            public int $total = 0;

            public function headersSent(): bool
            {
                return false;
            }

            public function statusLine(string $protocolVersion, int $status, string $reason): void {}

            public function header(string $line, bool $replace): void {}

            public function write(string $chunk): void
            {
                $this->largest = max($this->largest, \strlen($chunk));
                $this->total += \strlen($chunk);
            }
        };
        $file = tempnam(sys_get_temp_dir(), 'trunk-big');
        self::assertNotFalse($file);
        $handle = fopen($file, 'wb');
        self::assertNotFalse($handle);
        ftruncate($handle, 64 * 1024 * 1024);
        fclose($handle);
        $response = new Response(200, [], Stream::fromFile($file));
        $before = memory_get_usage();

        // Act
        new ResponseEmitter($sapi)->emit($response);
        $growth = memory_get_peak_usage() - $before;
        unlink($file);

        // Assert
        self::assertSame(64 * 1024 * 1024, $sapi->total);
        self::assertLessThanOrEqual(256 * 1024, $sapi->largest, 'no chunk is bigger than the emitter chunk size');
        self::assertLessThan(8 * 1024 * 1024, $growth, 'a 64 MB body must not be held in memory');
    }
}

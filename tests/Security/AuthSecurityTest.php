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
use Trunk\Auth\Cookie\Cookie;
use Trunk\Support\Directory;
use Trunk\Tests\Support\AuthApp;

/**
 * Hostile input and secret handling for the auth package.
 */
final class AuthSecurityTest extends TestCase
{
    private ?AuthApp $app = null;

    private ?string $logs = null;

    protected function tearDown(): void
    {
        $this->app?->cleanUp();

        if ($this->logs !== null) {
            new Directory()->remove($this->logs);
        }
    }

    public function test_the_auth_source_has_no_weak_primitives_no_serialization_and_uses_constant_time_comparison_for_secrets(): void
    {
        // Arrange
        $forbidden = ['unserialize(', 'serialize(', 'eval(', 'md5(', 'sha1(', 'crc32(', 'rand(', 'mt_rand(', 'uniqid(', 'shuffle(', 'str_shuffle(', 'array_rand(', 'lcg_value(', 'exec(', 'shell_exec(', 'system(', 'passthru(', 'proc_open(', 'popen(', 'extract(', 'include(', 'require('];
        $hits = [];
        $sources = [];

        // Act
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../packages/auth/src')) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $raw = (string) file_get_contents($file->getPathname());
            $sources[$file->getFilename()] = $raw;
            $code = implode('', array_map(static fn(PhpToken $t): string => $t->is([T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) ? '' : $t->text, PhpToken::tokenize($raw)));

            foreach ($forbidden as $needle) {
                if (preg_match('/(?<![>:\w])' . preg_quote($needle, '/') . '/', $code) === 1) {
                    $hits[] = $file->getFilename() . ': ' . $needle;
                }
            }
        }

        // Assert
        self::assertSame([], $hits);

        foreach (['CsrfTokens.php', 'TokenManager.php', 'Auth.php'] as $comparesSecrets) {
            self::assertStringContainsString('hash_equals(', $sources[$comparesSecrets], $comparesSecrets . ' compares a secret and must do it in constant time');
        }

        self::assertStringContainsString('random_bytes(', $sources['SessionId.php']);
        self::assertStringContainsString('random_bytes(', $sources['TokenManager.php']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function badCookies(): iterable
    {
        yield 'newline in the value' => ['session', "abc\r\nSet-Cookie: admin=1", '/'];
        yield 'semicolon in the value' => ['session', 'a; Domain=evil.example', '/'];
        yield 'comma in the value' => ['session', 'a,b', '/'];
        yield 'space in the value' => ['session', 'a b', '/'];
        yield 'quote in the value' => ['session', '"a"', '/'];
        yield 'nul in the value' => ['session', "a\0b", '/'];
        yield 'equals sign in the name' => ['a=b', 'x', '/'];
        yield 'newline in the name' => ["a\nb", 'x', '/'];
        yield 'space in the name' => ['a b', 'x', '/'];
        yield 'semicolon in the path' => ['session', 'x', '/; Domain=evil.example'];
        yield 'newline in the path' => ['session', 'x', "/\r\nX: y"];
        yield 'relative path' => ['session', 'x', 'admin'];
        yield 'empty name' => ['', 'x', '/'];
        yield 'huge value' => ['session', str_repeat('a', 4097), '/'];
    }

    #[DataProvider('badCookies')]
    public function test_a_cookie_that_could_break_out_of_its_header_cannot_be_built(string $name, string $value, string $path): void
    {
        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        new Cookie($name, $value, null, $path);
    }

    public function test_cookie_prefix_and_samesite_rules_are_enforced_and_no_built_header_ever_contains_a_line_break(): void
    {
        // Arrange
        $invalid = [
            static fn() => new Cookie('__Host-a', 'x', null, '/', null, false),
            static fn() => new Cookie('__Host-a', 'x', null, '/app'),
            static fn() => new Cookie('__Host-a', 'x', null, '/', 'example.com'),
            static fn() => new Cookie('__Secure-a', 'x', null, '/', null, false),
            static fn() => new Cookie('a', 'x', null, '/', null, false, true, 'None'),
            static fn() => new Cookie('a', 'x', null, '/', null, true, true, 'Sometimes'),
            static fn() => new Cookie('a', 'x', -1),
            static fn() => new Cookie('a', 'x', null, '/', 'evil.example;x'),
        ];
        $refused = 0;

        // Act
        foreach ($invalid as $make) {
            try {
                $make();
            } catch (InvalidArgumentException) {
                ++$refused;
            }
        }

        mt_srand(11);
        $headers = [];
        for ($i = 0; $i < 300; ++$i) {
            $value = '';
            for ($j = 0, $n = mt_rand(0, 40); $j < $n; ++$j) {
                $value .= \chr(mt_rand(0x21, 0x7E));
            }

            try {
                $headers[] = new Cookie('c' . $i, $value)->header();
            } catch (InvalidArgumentException) {
                // a value using a forbidden character is refused, which is the point
            }
        }

        // Assert
        self::assertSame(\count($invalid), $refused);
        self::assertNotEmpty($headers);
        foreach ($headers as $header) {
            self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $header));
            self::assertSame(1, substr_count($header, '; HttpOnly'));
        }

        self::assertStringNotContainsString('hunter2', print_r(new Cookie('session', 'hunter2'), true));
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
    public function test_hostile_cookies_tokens_and_csrf_values_never_cause_a_server_error(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $app->createUser('ada@example.com');
        $client = $app->client($mode);
        mt_srand(5);
        $pieces = ["\r\n", "\0", '"', "'", ';', ',', '\\', '../', '{', '}', '$', '`', "\xC3\x28", "\xFF", '%00', '%0d%0a', str_repeat('A', 100), 'trk_', 'Bearer ', '<script>'];
        $codes = [];

        // Act
        for ($i = 0; $i < 120; ++$i) {
            $junk = '';
            for ($j = 0, $n = mt_rand(1, 6); $j < $n; ++$j) {
                $junk .= $pieces[mt_rand(0, \count($pieces) - 1)] . dechex(mt_rand(0, 65535));
            }

            $hostile = $app->client($mode);
            $hostile->cookies = ['session' => $junk, '__Host-session' => $junk];

            foreach ([
                static fn() => $hostile->get('/web/me'),
                static fn() => $hostile->post('/web/login', ['email' => $junk, 'password' => $junk, '_csrf' => $junk]),
                static fn() => $hostile->send('POST', '/web/login', ['email' => 'a@b.c', 'password' => 'x'], ['X-CSRF-Token' => $junk]),
                static fn() => $hostile->get('/api/whoami', ['Authorization' => 'Bearer ' . $junk]),
            ] as $request) {
                try {
                    $codes[] = $request()->getStatusCode();
                } catch (InvalidArgumentException) {
                    // The HTTP layer itself refuses header values it cannot represent; that is a rejection, not a crash.
                    $codes[] = 400;
                }
            }
        }

        // Assert
        self::assertNotContains(500, $codes);
        self::assertEmpty(array_filter($codes, static fn(int $c): bool => $c < 200 || $c >= 500), 'only clean 2xx-4xx answers');
    }

    #[DataProvider('modes')]
    public function test_array_valued_and_oversized_csrf_fields_are_just_invalid(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $client = $app->client($mode);
        $client->csrf();

        // Act
        $request = new \Trunk\Http\Message\ServerRequest('POST', 'http://app.test/web/login', ['Accept' => 'application/json'])->withCookieParams($client->cookies)->withParsedBody(['_csrf' => ['a', 'b'], 'email' => ['x'], 'password' => ['y']]);
        $array = $app->kernel($mode)->handle($request);
        $huge = $client->post('/web/login', ['_csrf' => str_repeat('A', 1_000_000), 'email' => 'a', 'password' => 'b']);

        // Assert
        self::assertSame(403, $array->getStatusCode());
        self::assertSame(403, $huge->getStatusCode());
    }

    #[DataProvider('modes')]
    public function test_anonymous_traffic_never_gets_a_cookie_or_a_session_row(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $client = $app->client($mode);

        // Act
        foreach (['/web/get/x', '/web/me', '/web/page'] as $path) {
            $client->get($path);
        }

        // Assert
        self::assertSame([], $client->received);
        self::assertSame(0, $app->connection->table('trunk_sessions')->count());
    }

    #[DataProvider('modes')]
    public function test_no_secret_reaches_the_logs_even_on_failures(string $mode): void
    {
        // Arrange
        $this->logs = sys_get_temp_dir() . '/trunk-authlogs-' . bin2hex(random_bytes(4));
        $app = $this->app = new AuthApp(logging: ['channel' => 'file', 'format' => 'json', 'level' => 'debug', 'path' => $this->logs]);
        $user = $app->user($app->createUser('ada@example.com', 'the-password-1234'));
        $token = $app->tokens()->issue($user, 'ci');
        $client = $app->client($mode);
        $csrf = $client->csrf();
        $sessionId = (string) $client->sessionCookie();

        // Act
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'wrong-password-5678', '_csrf' => $csrf]);
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'the-password-1234', '_csrf' => 'forged-csrf-value']);
        $client->get('/api/whoami', ['Authorization' => 'Bearer ' . $token->plainText . 'tampered']);
        $client->get('/api/whoami', ['Authorization' => 'Bearer ' . $token->plainText]);
        $client->post('/web/login', ['email' => 'ada@example.com', 'password' => 'the-password-1234', '_csrf' => $csrf]);
        $logs = implode("\n", array_map(static fn(string $f): string => (string) file_get_contents($f), glob($this->logs . '/*.log') ?: []));

        // Assert
        self::assertNotSame('', $logs, 'the failures were logged');
        $secret = explode('.', substr($token->plainText, 4))[1];

        foreach (['the-password-1234', 'wrong-password-5678', 'forged-csrf-value', $secret, $sessionId, $csrf] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $logs);
        }
    }
}

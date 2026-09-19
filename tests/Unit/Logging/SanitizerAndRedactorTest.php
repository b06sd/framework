<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Logging;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;
use Trunk\Logging\ContextNormalizer;
use Trunk\Logging\Redactor;
use Trunk\Logging\Sanitizer;

final class SanitizerAndRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileText(): iterable
    {
        yield 'forged log line' => ["ok\n2026-09-19T00:00:00.000Z ERROR admin logged in"];
        yield 'carriage return' => ["ok\rERROR forged"];
        yield 'ansi escape' => ["\x1b[31mred\x1b[0m"];
        yield 'nul byte' => ["a\0b"];
        yield 'bell and backspace' => ["a\x07\x08b"];
        yield 'unicode line separator' => ["a\u{2028}b\u{2029}c"];
        yield 'bidi override' => ["invoice\u{202E}fdp.exe"];
        yield 'zero width' => ["a\u{200B}b\u{FEFF}"];
        yield 'delete char' => ["a\x7Fb"];
    }

    #[DataProvider('hostileText')]
    public function test_control_characters_and_line_breaks_never_survive(string $input): void
    {
        // Arrange & Act
        $clean = Sanitizer::text($input);

        // Assert
        self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $clean), 'no raw control characters');
        self::assertSame(0, preg_match('/[\x{0085}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200F}\x{FEFF}]/u', $clean), 'no raw bidi or separator characters');
        self::assertStringNotContainsString("\n", $clean);
    }

    public function test_normal_text_is_untouched_and_invalid_utf8_and_length_are_handled(): void
    {
        // Arrange & Act & Assert
        self::assertSame('Grüße, 你好 — ok', Sanitizer::text('Grüße, 你好 — ok'));
        self::assertSame(1, preg_match('//u', Sanitizer::text("a\xC3\x28b")));
        self::assertSame(1, preg_match('//u', Sanitizer::text("bad \xFF\xFE bytes")));
        $long = Sanitizer::text(str_repeat('é', 10000), 100);
        self::assertSame(1, preg_match('//u', $long));
        self::assertStringEndsWith('...[truncated]', $long);
        self::assertLessThan(140, \strlen($long));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveKeys(): iterable
    {
        foreach (['password', 'PASSWORD', 'user_password_hash', 'passwd', 'secret', 'client_secret', 'token', 'access_token', 'refresh_token', 'X-Auth-Token', 'authorization', 'Authorization', 'cookie', 'Set-Cookie', 'api_key', 'apiKey', 'X-Api-Key', 'private_key', 'credit_card', 'cardNumber', 'cvv', 'session.id'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('sensitiveKeys')]
    public function test_sensitive_keys_are_recognised_whatever_their_spelling(string $key): void
    {
        // Arrange & Act & Assert
        self::assertTrue(new Redactor()->sensitive($key));
    }

    public function test_ordinary_keys_and_application_extras(): void
    {
        // Arrange
        $redactor = new Redactor(['ssn', 'Tax-Id']);

        // Act & Assert
        foreach (['workflowId', 'userId', 'name', 'email', 'status'] as $key) {
            self::assertFalse($redactor->sensitive($key), $key);
        }
        self::assertTrue($redactor->sensitive('ssn'));
        self::assertTrue($redactor->sensitive('customer_TAX_ID'));
        self::assertTrue($redactor->sensitive('password'), 'defaults can be extended, never removed');
    }

    public function test_secrets_inside_free_text_are_scrubbed(): void
    {
        // Arrange
        $redactor = new Redactor();

        // Act & Assert
        self::assertSame('Authorization: Bearer [REDACTED] rejected', $redactor->scrub('Authorization: Bearer abcdef1234567890 rejected'));
        self::assertSame('connect mysql://[REDACTED]@db/app', $redactor->scrub('connect mysql://root:hunter2@db/app'));
        self::assertSame('login failed password=[REDACTED] user=ada', $redactor->scrub('login failed password=hunter2 user=ada'));
        self::assertSame('api_key: [REDACTED]', $redactor->scrub('api_key: sk_live_123456'));
        self::assertSame('nothing secret here', $redactor->scrub('nothing secret here'));
    }

    public function test_the_normalizer_redacts_nested_keys_reduces_objects_and_bounds_depth(): void
    {
        // Arrange
        $normalizer = new ContextNormalizer();
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'too deep']]]]]]];

        // Act
        $result = $normalizer->normalize([
            'user' => ['name' => 'Ada', 'Password' => 'hunter2', 'profile' => ['api_key' => 'k']],
            'headers' => ['Authorization' => 'Bearer x', 'Accept' => 'text/html'],
            'object' => new stdClass(),
            'closure' => static fn() => 1,
            'file' => fopen('php://memory', 'r'),
            'deep' => $deep,
            'float' => NAN,
            "evil\nkey" => 'v',
        ]);
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        // Assert
        self::assertSame('Ada', self::at($result, 'user', 'name'));
        self::assertSame(Redactor::MASK, self::at($result, 'user', 'Password'));
        self::assertSame(Redactor::MASK, self::at($result, 'user', 'profile', 'api_key'));
        self::assertSame(Redactor::MASK, self::at($result, 'headers', 'Authorization'));
        self::assertSame('text/html', self::at($result, 'headers', 'Accept'));
        self::assertSame('[object stdClass]', $result['object']);
        self::assertSame('[object Closure]', $result['closure']);
        self::assertSame('[resource stream]', $result['file']);
        self::assertStringContainsString('nested too deeply', $json);
        self::assertStringNotContainsString('hunter2', $json);
        self::assertArrayHasKey('evil\\nkey', $result);
    }

    public function test_exceptions_are_logged_without_arguments_and_with_scrubbed_messages(): void
    {
        // Arrange
        $thrower = static function (string $password): never {
            throw new RuntimeException('failed with password=' . $password . ' at https://root:pw@host/x', 12, new LogicException('inner secret=abc'));
        };

        try {
            $thrower('hunter2');
        } catch (Throwable $e) {
            $error = $e;
        }

        // Act
        $data = new ContextNormalizer()->normalize(['exception' => $error]);
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        // Assert
        self::assertSame(RuntimeException::class, self::at($data, 'exception', 'class'));
        self::assertSame(12, self::at($data, 'exception', 'code'));
        self::assertStringContainsString('password=[REDACTED]', (string) json_encode(self::at($data, 'exception', 'message')));
        self::assertStringNotContainsString('hunter2', $json);
        self::assertStringNotContainsString('root:pw', $json);
        self::assertStringContainsString('secret=[REDACTED]', $json);
        self::assertNotEmpty(self::at($data, 'exception', 'trace'));
    }

    private static function at(mixed $value, string ...$path): mixed
    {
        foreach ($path as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }
}

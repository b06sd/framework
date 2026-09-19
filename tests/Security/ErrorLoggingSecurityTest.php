<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use DateTimeImmutable;
use LogicException;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Trunk\Logging\ContextNormalizer;
use Trunk\Logging\JsonFormatter;
use Trunk\Logging\LogHandler;
use Trunk\Logging\Redactor;
use Trunk\Logging\StructuredLogger;

/**
 * Attacker-controlled text and keys must never break a log line, forge another one, or carry a
 * secret out; and the error/logging code itself must be free of dynamic execution.
 */
final class ErrorLoggingSecurityTest extends TestCase
{
    public function test_fuzzed_messages_keys_and_values_always_produce_exactly_one_valid_json_line(): void
    {
        // Arrange
        [$logger, $sink] = $this->logger();

        // Act
        for ($seed = 1; $seed <= 300; ++$seed) {
            $logger->log('info', $this->hostile($seed), [$this->hostile($seed + 1000) => $this->hostile($seed + 2000), 'nested' => [$this->hostile($seed + 3000) => [$this->hostile($seed + 4000)]], 'exception' => new RuntimeException($this->hostile($seed + 5000))]);
        }

        // Assert
        self::assertCount(300, $sink->lines);

        foreach ($sink->lines as $i => $line) {
            self::assertSame(1, substr_count($line, "\n"), 'record ' . $i . ' spans one line');
            self::assertStringEndsWith("}\n", $line);
            $decoded = json_decode($line, true, 64);
            self::assertIsArray($decoded, 'record ' . $i . ' is valid JSON');
            self::assertSame('INFO', $decoded['level']);
            self::assertSame(1, preg_match('//u', $line), 'record ' . $i . ' is valid UTF-8');
            self::assertSame(0, preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', substr($line, 0, -1)), 'no raw control characters');
            self::assertStringNotContainsString('hunter2', $line);
            self::assertStringNotContainsString('abcdefghijklmnop', $line);
        }
    }

    public function test_secrets_are_redacted_at_any_depth_in_any_spelling(): void
    {
        // Arrange
        [$logger, $sink] = $this->logger();
        $spellings = ['password', 'PASSWORD', 'Pass_Word', 'user.password', 'x-api-key', 'Set-Cookie', 'client_secret', 'refreshToken', 'Authorization', 'private-key', 'card_number', 'CVV'];

        // Act
        foreach ($spellings as $key) {
            $logger->info('event', [$key => 'the-secret-value', 'deep' => ['a' => ['b' => [$key => 'the-secret-value']]], 'list' => [['x' => [$key => 'the-secret-value']]]]);
        }

        // Assert
        self::assertStringNotContainsString('the-secret-value', implode('', $sink->lines));
        self::assertGreaterThanOrEqual(12, substr_count(implode('', $sink->lines), '[REDACTED]'));
    }

    public function test_a_hostile_object_in_the_context_is_never_stringified_or_traversed(): void
    {
        // Arrange
        [$logger, $sink] = $this->logger();
        $evil = new class {
            public string $secret = 'leaked-property';

            public function __toString(): string
            {
                throw new LogicException('__toString must not be called');
            }

            public function __get(string $name): never
            {
                throw new LogicException('properties must not be read');
            }
        };

        // Act
        $logger->info('object', ['thing' => $evil, 'items' => [$evil]]);

        // Assert
        self::assertCount(1, $sink->lines);
        self::assertStringNotContainsString('leaked-property', $sink->lines[0]);
        self::assertStringContainsString('[object class@anonymous', $sink->lines[0]);
    }

    public function test_the_error_logging_and_lifecycle_code_has_no_dynamic_execution_or_unserialization(): void
    {
        // Arrange
        $forbidden = ['eval(', 'unserialize(', 'shell_exec(', 'system(', 'passthru(', 'proc_open(', 'popen(', 'exec(', 'assert(', 'create_function', 'include(', 'require(', 'extract('];
        $hits = [];

        // Act
        foreach (['src/Error', 'src/Logging', 'src/Lifecycle', 'packages/http/src/Error', 'packages/http/src/Middleware'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../' . $directory)) as $file) {
                if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = implode('', array_map(static fn(PhpToken $t): string => $t->is([T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) ? '' : $t->text, PhpToken::tokenize((string) file_get_contents($file->getPathname()))));

                foreach ($forbidden as $needle) {
                    if (preg_match('/(?<![>:\w])' . preg_quote($needle, '/') . '/', $code) === 1) {
                        $hits[] = $file->getFilename() . ': ' . $needle;
                    }
                }
            }
        }

        // Assert
        self::assertSame([], $hits);
    }
    /**
     * @return array{StructuredLogger, object{lines: list<string>}}
     */
    private function logger(): array
    {
        $sink = new class implements LogHandler {
            /** @var list<string> */
            public array $lines = [];

            public function write(string $line): void
            {
                $this->lines[] = $line;
            }
        };

        return [new StructuredLogger(new JsonFormatter(), $sink, 'debug', null, 'svc', 'production', new ContextNormalizer(new Redactor()), static fn(): DateTimeImmutable => new DateTimeImmutable('2026-01-01 00:00:00 UTC')), $sink];
    }

    private function hostile(int $seed): string
    {
        mt_srand($seed);
        $pieces = ["\n", "\r", "\r\n", "\0", "\x1b[31m", "\x07", "\u{2028}", "\u{202E}", '"', '\\', "'", '{', '}', '$', '`', "\xC3\x28", "\xFF", "\xE2\x82", 'ERROR', '{"level":"CRITICAL"}', 'Bearer abcdefghijklmnop', 'password=hunter2'];
        $text = '';

        for ($i = 0, $n = mt_rand(1, 12); $i < $n; ++$i) {
            $text .= $pieces[mt_rand(0, \count($pieces) - 1)] . dechex(mt_rand(0, 65535));
        }

        return $text;
    }
}

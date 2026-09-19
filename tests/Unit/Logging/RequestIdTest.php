<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Logging;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Trunk\Logging\RequestId;
use Trunk\Logging\TraceParent;

final class RequestIdTest extends TestCase
{
    public function test_generated_ids_are_prefixed_unique_time_sortable_and_log_safe(): void
    {
        // Arrange & Act
        $first = RequestId::generate();
        usleep(2000);
        $second = RequestId::generate();
        $ids = array_map(static fn(): string => RequestId::generate(), range(1, 500));

        // Assert
        self::assertMatchesRegularExpression('/^req_[0-9A-HJKMNP-TV-Z]{26}$/D', $first);
        self::assertLessThan($second, $first, 'ids sort by creation time');
        self::assertCount(500, array_unique($ids));
        self::assertStringStartsWith('job_', RequestId::generate('job'));
        self::assertSame($first, RequestId::accept($first));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function rejectedIds(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'too short' => ['abc'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'newline' => ["req_abcdefgh\nERROR forged"];
        yield 'space' => ['req abcdefgh'];
        yield 'quote' => ['req_"abcdefgh'];
        yield 'angle brackets' => ['<script>alert(1)</script>'];
        yield 'leading dash' => ['-abcdefghij'];
        yield 'unicode' => ["req_abcdéfgh"];
        yield 'nul byte' => ["req_abcd\0efgh"];
        yield 'sql' => ["x'; DROP TABLE t; --"];
    }

    #[DataProvider('rejectedIds')]
    public function test_unsafe_inbound_ids_are_rejected(?string $inbound): void
    {
        // Arrange & Act & Assert
        self::assertNull(RequestId::accept($inbound));
    }

    public function test_safe_inbound_ids_are_kept(): void
    {
        // Arrange & Act & Assert
        foreach (['req_01K8ZQ3T5W9Y2M4N6P8R0S1V3X', 'b3f2a9c4-1d2e-4f5a-8b6c-7d8e9f0a1b2c', 'gateway.2026-09-19.abc123'] as $id) {
            self::assertSame($id, RequestId::accept($id));
        }
    }

    public function test_traceparent_is_parsed_strictly(): void
    {
        // Arrange
        $valid = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        // Act & Assert
        self::assertSame(['traceId' => '4bf92f3577b34da6a3ce929d0e0e4736', 'spanId' => '00f067aa0ba902b7'], TraceParent::parse($valid));

        foreach ([null, '', 'garbage', '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', '00-00000000000000000000000000000000-00f067aa0ba902b7-01', '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01', '00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01', $valid . "\nX", '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7'] as $bad) {
            self::assertNull(TraceParent::parse($bad), (string) $bad);
        }

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', TraceParent::newTraceId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', TraceParent::newSpanId());
    }

    public function test_the_encoding_matches_the_plain_bit_string_definition_for_any_input(): void
    {
        // Arrange
        $reference = static function (string $bytes): string {
            $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
            $bits = '00';

            foreach (str_split($bytes) as $byte) {
                $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
            }

            return implode('', array_map(static fn(string $g): string => $alphabet[(int) bindec($g)], str_split($bits, 5)));
        };
        $encode = new ReflectionMethod(RequestId::class, 'encode');

        // Act & Assert
        foreach ([str_repeat("\0", 16), str_repeat("\xFF", 16), ...array_map(static fn(): string => random_bytes(16), range(1, 500))] as $bytes) {
            self::assertSame($reference($bytes), $encode->invoke(null, $bytes));
        }
    }
}

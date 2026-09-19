<?php

declare(strict_types=1);

namespace Trunk\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class Response extends Message implements ResponseInterface
{
    private int $statusCode;

    private string $reasonPhrase;

    /**
     * @param array<array-key, mixed> $headers
     */
    public function __construct(int $status = 200, array $headers = [], ?StreamInterface $body = null, string $version = '1.1', string $reason = '')
    {
        $this->statusCode = self::assertStatus($status);
        $this->reasonPhrase = self::reasonFor($status, $reason);
        $this->initialize($headers, $body, $version);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $new = clone $this;
        $new->statusCode = self::assertStatus($code);
        $new->reasonPhrase = self::reasonFor($code, $reasonPhrase);

        return $new;
    }

    private static function assertStatus(int $status): int
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(\sprintf('Invalid HTTP status code %d.', $status));
        }

        return $status;
    }

    private static function reasonFor(int $status, string $reason): string
    {
        if ($reason !== '') {
            if (preg_match('/^[\x09\x20-\x7E\x80-\xFF]*$/D', $reason) !== 1) {
                throw new InvalidArgumentException('The reason phrase contains forbidden characters.');
            }

            return $reason;
        }

        return match ($status) {
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            206 => 'Partial Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Content Too Large',
            414 => 'URI Too Long',
            415 => 'Unsupported Media Type',
            418 => "I'm a teapot",
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => '',
        };
    }
}

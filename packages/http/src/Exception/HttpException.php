<?php

declare(strict_types=1);

namespace Trunk\Http\Exception;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Trunk\Error\ErrorCode;
use Trunk\Error\PublicError;

/**
 * Signals an HTTP error response. The exception message is for logs and debug output only; clients
 * receive the standard reason phrase and the error code. (To send a custom client-safe message,
 * write your own exception implementing PublicError.)
 *
 * @api
 */
final class HttpException extends RuntimeException implements PublicError
{
    private const array PHRASES = [
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 406 => 'Not Acceptable', 408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone',
        413 => 'Content Too Large', 414 => 'URI Too Long', 415 => 'Unsupported Media Type', 422 => 'Unprocessable Content', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    private readonly ?string $errorCodeName;

    /**
     * @param array<string, string> $headers
     * @param string|null           $code    a stable error code; defaults from the status
     */
    public function __construct(
        public readonly int $statusCode,
        string $message = '',
        public readonly array $headers = [],
        ?Throwable $previous = null,
        ?string $code = null,
    ) {
        if ($statusCode < 400 || $statusCode > 599) {
            throw new InvalidArgumentException(\sprintf('HttpException needs a 4xx or 5xx status, %d given.', $statusCode));
        }

        if ($code !== null && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('An error code uses A-Z, 0-9 and underscores, e.g. CUSTOMER_NOT_FOUND.');
        }

        $this->errorCodeName = $code;

        parent::__construct($message !== '' ? $message : \sprintf('HTTP %d', $statusCode), $statusCode, $previous);
    }

    public static function badRequest(string $message = ''): self
    {
        return new self(400, $message);
    }

    public static function notFound(string $message = '', ?string $code = null): self
    {
        return new self(404, $message, code: $code);
    }

    /**
     * @param non-empty-list<string> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'Method not allowed.', ['Allow' => implode(', ', $allowed)]);
    }

    public function errorCode(): string
    {
        return $this->errorCodeName ?? match ($this->statusCode) {
            400 => ErrorCode::BadRequest->value,
            401 => ErrorCode::AuthenticationRequired->value,
            403 => ErrorCode::AccessDenied->value,
            404 => ErrorCode::NotFound->value,
            405 => ErrorCode::MethodNotAllowed->value,
            413 => ErrorCode::PayloadTooLarge->value,
            415 => ErrorCode::UnsupportedMediaType->value,
            422 => ErrorCode::ValidationFailed->value,
            default => $this->statusCode >= 500 ? ErrorCode::InternalError->value : 'HTTP_' . $this->statusCode,
        };
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function publicMessage(): string
    {
        return self::PHRASES[$this->statusCode] ?? ($this->statusCode >= 500 ? 'Server Error' : 'Client Error');
    }

    public function details(): array
    {
        return [];
    }
}

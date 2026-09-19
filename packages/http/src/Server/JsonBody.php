<?php

declare(strict_types=1);

namespace Trunk\Http\Server;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Error\ErrorCode;
use Trunk\Http\Exception\HttpException;

/**
 * Reads a JSON request body safely: the content type must be JSON (415), the size is bounded (413),
 * the depth is bounded, and decoding is strict (400). Returns the decoded object or array. Inject
 * it where you read JSON instead of calling json_decode() on the raw body.
 *
 * @api
 */
final readonly class JsonBody
{
    /** @internal wired by the container, not part of the API */
    public function __construct(private RequestLimits $limits = new RequestLimits()) {}

    /**
     * @return array<array-key, mixed>
     *
     * @throws HttpException
     */
    public function decode(ServerRequestInterface $request): array
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));

        if ($type !== 'application/json' && !str_ends_with($type, '+json')) {
            throw new HttpException(415, 'Expected a JSON body.', code: ErrorCode::UnsupportedMediaType->value);
        }

        $body = $request->getBody();
        $size = $body->getSize();

        if ($size !== null && $size > $this->limits->maxBodyBytes) {
            throw new HttpException(413, 'The JSON body is too large.', code: ErrorCode::PayloadTooLarge->value);
        }

        $raw = '';

        while (!$body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                break;
            }

            $raw .= $chunk;

            if (\strlen($raw) > $this->limits->maxBodyBytes) {
                throw new HttpException(413, 'The JSON body is too large.', code: ErrorCode::PayloadTooLarge->value);
            }
        }

        try {
            $decoded = json_decode($raw, true, max(1, $this->limits->maxJsonDepth), \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'The JSON body is not valid.', code: ErrorCode::BadRequest->value);
        }

        return \is_array($decoded) ? $decoded : throw new HttpException(400, 'The JSON body must be an object or an array.', code: ErrorCode::BadRequest->value);
    }
}

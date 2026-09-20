<?php

declare(strict_types=1);

namespace Trunk\Validation\Http;

use Psr\Http\Message\ServerRequestInterface;
use Trunk\Error\ValidationException;
use Trunk\Http\Server\JsonBody;
use Trunk\Validation\Plans;
use Trunk\Validation\Source;
use Trunk\Validation\Validator;

/**
 * Validates a request in one call: `$dto = $this->requests->validate(RegisterRequest::class, $request)`.
 * JSON bodies are read safely (size, depth, content type), form bodies come from the parsed body, and
 * GET/HEAD read the query string. Say otherwise with `#[From(Source::Query)]` on the class.
 *
 * @api
 */
final readonly class RequestValidator
{
    /** @internal wired by the container, not part of the API */
    public function __construct(private Validator $validator, private JsonBody $json, private Plans $plans) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     *
     * @throws ValidationException
     */
    public function validate(string $class, ServerRequestInterface $request): object
    {
        $source = $this->plans->plan($class)->source ?? $this->source($request);

        $input = match ($source) {
            Source::Json => $this->json->decode($request),
            Source::Form => (array) $request->getParsedBody(),
            Source::Query => $request->getQueryParams(),
        };

        return $this->validator->validate($class, $input, $source);
    }

    private function source(ServerRequestInterface $request): Source
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));

        return match (true) {
            \in_array($request->getMethod(), ['GET', 'HEAD'], true) => Source::Query,
            $type === 'application/json' || str_ends_with($type, '+json') => Source::Json,
            default => Source::Form,
        };
    }
}

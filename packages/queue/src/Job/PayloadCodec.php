<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use JsonException;
use stdClass;
use Trunk\Queue\Exception\InvalidPayload;

/**
 * Payload data <-> JSON text, with a size cap and a depth limit. There is no other format: no
 * serialize(), no class names in the data.
 */
final readonly class PayloadCodec
{
    private const int DEPTH = 16;

    public function __construct(private int $maxBytes = 65536) {}

    /**
     * @param array<string, mixed> $data
     */
    public function encode(array $data, string $job): string
    {
        try {
            $json = json_encode($data === [] ? new stdClass() : $data, \JSON_THROW_ON_ERROR, self::DEPTH);
        } catch (JsonException) {
            throw new InvalidPayload(\sprintf('Cannot encode job %s: the payload is not valid JSON data (invalid UTF-8, or too deeply nested).', $job));
        }

        if (\strlen($json) > $this->maxBytes) {
            throw new InvalidPayload(\sprintf('The payload of job %s is larger than the %d byte limit. Store the data elsewhere and pass an id.', $job, $this->maxBytes));
        }

        return $json;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function parse(string $payload, string $job): array
    {
        if (\strlen($payload) > $this->maxBytes) {
            throw new InvalidPayload(\sprintf('The stored payload of job %s exceeds the %d byte limit.', $job, $this->maxBytes));
        }

        try {
            $data = json_decode($payload, true, self::DEPTH, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidPayload(\sprintf('The stored payload of job %s is not valid JSON.', $job));
        }

        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidPayload(\sprintf('The stored payload of job %s must be a JSON object.', $job));
        }

        return $data;
    }
}

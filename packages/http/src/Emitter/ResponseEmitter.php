<?php

declare(strict_types=1);

namespace Trunk\Http\Emitter;

use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Exception\EmitterException;
use Trunk\Http\Message\Headers;

/**
 * Writes a PSR-7 response through a Sapi. Headers are re-validated because the response may come
 * from any implementation, not only this package.
 */
final readonly class ResponseEmitter
{
    public function __construct(
        private Sapi $sapi = new PhpSapi(),
        private int $chunkSize = 8192,
    ) {}

    /**
     * @param bool $withBody false for a response to HEAD: same status and headers, no content (RFC 9110)
     */
    public function emit(ResponseInterface $response, bool $withBody = true): void
    {
        if ($this->sapi->headersSent()) {
            throw new EmitterException('Headers have already been sent.');
        }

        $status = $response->getStatusCode();
        $reason = $response->getReasonPhrase();

        if ($status < 100 || $status > 599) {
            throw new EmitterException(\sprintf('Invalid status code %d.', $status));
        }

        // Validate everything before the first byte is written.
        $lines = [];

        foreach ($response->getHeaders() as $name => $values) {
            Headers::assertName((string) $name);
            $replace = strtolower((string) $name) !== 'set-cookie';

            foreach ($values as $value) {
                if (!Headers::isValidValue($value)) {
                    throw new EmitterException(\sprintf('Header "%s" contains forbidden characters.', $name));
                }

                $lines[] = [$name . ': ' . $value, $replace];
                $replace = false;
            }
        }

        $this->sapi->statusLine($response->getProtocolVersion(), $status, Headers::isValidValue($reason) ? $reason : '');

        foreach ($lines as [$line, $replace]) {
            $this->sapi->header($line, $replace);
        }

        if (!$withBody || $status < 200 || $status === 204 || $status === 304) {
            return;
        }

        $this->emitBody($response);
    }

    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();

        if (!$body->isReadable()) {
            return;
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            $chunk = $body->read($this->chunkSize);

            if ($chunk === '') {
                break;
            }

            $this->sapi->write($chunk);
        }
    }
}

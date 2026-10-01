<?php

declare(strict_types=1);

namespace Trunk\Http\Emitter;

use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Exception\EmitterException;
use Trunk\Http\Message\Headers;

/**
 * Writes a PSR-7 response through a Sapi. Headers are re-validated because the response may come
 * from any implementation, not only this package.
 *
 * A body of known size (a seekable stream: a string, a file) is written in chunks and left to PHP's
 * output layer to send, and gets a Content-Length when the response has none, so the connection can
 * be reused without chunked framing; never more than that many bytes are written, even if a file
 * grows meanwhile. It is not flushed: flushing costs a write per chunk and stops an ob_gzhandler
 * buffer from compressing. A body of unknown size (a non-seekable stream) is flushed after every
 * chunk so it reaches the client as it is produced. No Content-Length is added to a HEAD response, to one with Transfer-Encoding,
 * or when the runtime rewrites output (compression).
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

        $hasBody = $withBody && $status >= 200 && $status !== 204 && $status !== 304;
        $length = $hasBody ? $this->declaredLength($response) : null;

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

        if ($length !== null) {
            $lines[] = ['Content-Length: ' . $length, true];
        }

        $this->sapi->statusLine($response->getProtocolVersion(), $status, Headers::isValidValue($reason) ? $reason : '');

        foreach ($lines as [$line, $replace]) {
            $this->sapi->header($line, $replace);
        }

        if ($hasBody) {
            $this->emitBody($response, $length);
        }
    }

    /**
     * The Content-Length to add, or null to leave the framing to the server.
     */
    private function declaredLength(ResponseInterface $response): ?int
    {
        $body = $response->getBody();

        if ($response->hasHeader('Content-Length') || $response->hasHeader('Transfer-Encoding') || !$body->isReadable() || !$body->isSeekable() || !$this->sapi->bodyPassesThrough()) {
            return null;
        }

        return $body->getSize();
    }

    private function emitBody(ResponseInterface $response, ?int $length): void
    {
        $body = $response->getBody();

        if (!$body->isReadable()) {
            return;
        }

        $streaming = !$body->isSeekable();

        if (!$streaming) {
            $body->rewind();
        }

        $remaining = $length ?? \PHP_INT_MAX;

        while ($remaining > 0 && !$body->eof()) {
            $chunk = $body->read(min($this->chunkSize, $remaining));

            if ($chunk === '') {
                break;
            }

            $remaining -= \strlen($chunk);
            $this->sapi->write($chunk);

            if ($streaming) {
                $this->sapi->flush();
            }
        }
    }
}

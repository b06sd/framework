<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;

/**
 * A tiny browser: keeps cookies between requests and sends them back like a browser would.
 */
final class AuthClient
{
    /** @var array<string, string> */
    public array $cookies = [];

    /** @var list<string> every Set-Cookie header received, in order */
    public array $received = [];

    public function __construct(private readonly HttpKernel $kernel, private readonly string $address = '203.0.113.7') {}

    /**
     * @param array<string, string> $headers
     */
    public function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->send('GET', $path, null, $headers);
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $headers
     */
    public function post(string $path, array $form = [], array $headers = []): ResponseInterface
    {
        return $this->send('POST', $path, $form, $headers);
    }

    /**
     * @param array<string, string>|null $form
     * @param array<string, string>      $headers
     */
    public function send(string $method, string $path, ?array $form, array $headers = [], ?string $address = null): ResponseInterface
    {
        $request = new ServerRequest($method, 'http://app.test' . $path, ['Accept' => 'application/json'])->withAttribute('client_ip', $address ?? $this->address);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($this->cookies !== []) {
            $request = $request->withCookieParams($this->cookies);
        }

        if ($form !== null) {
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withParsedBody($form);
        }

        $response = $this->kernel->handle($request);

        foreach ($response->getHeader('Set-Cookie') as $header) {
            $this->received[] = $header;
            [$pair] = explode(';', $header, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (str_contains($header, 'Max-Age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }

    public function csrf(): string
    {
        $token = $this->json($this->get('/web/csrf'))['token'] ?? '';

        return \is_string($token) ? $token : '';
    }

    /**
     * @return array<array-key, mixed>
     */
    public function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 16);

        return \is_array($decoded) ? $decoded : [];
    }

    public function sessionCookie(): ?string
    {
        foreach ($this->cookies as $name => $value) {
            if (str_ends_with($name, 'session')) {
                return $value;
            }
        }

        return null;
    }
}

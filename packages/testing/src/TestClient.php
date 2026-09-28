<?php

declare(strict_types=1);

namespace Trunk\Testing;

use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\Http\Stream\Stream;

/**
 * A tiny browser for a real, booted Trunk application: build one with `TestApp::client(...)`. Keeps
 * cookies between requests and sends them back, exactly like a browser would, so a login followed by
 * a request to a page behind it works without any extra wiring.
 *
 * @api
 */
final class TestClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    /** @var array<string, string> */
    private array $headers = [];

    /** @internal built by TestApp::client(), not part of the API */
    public function __construct(private readonly HttpKernel $kernel, private string $address = '127.0.0.1') {}

    /**
     * A header sent on every request this client makes from now on (for example `Authorization`).
     */
    public function withHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * The address every request appears to come from (the `client_ip` attribute); useful for testing
     * rate limits and login throttling without a real network.
     */
    public function withAddress(string $address): static
    {
        $this->address = $address;

        return $this;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function get(string $uri, array $query = []): TestResponse
    {
        return $this->send('GET', $uri, null, $query);
    }

    /**
     * A form post (`application/x-www-form-urlencoded`), the same way an HTML `<form>` would submit it.
     *
     * @param array<string, mixed> $data
     */
    public function post(string $uri, array $data = []): TestResponse
    {
        return $this->send('POST', $uri, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(string $uri, array $data = []): TestResponse
    {
        return $this->send('PUT', $uri, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function patch(string $uri, array $data = []): TestResponse
    {
        return $this->send('PATCH', $uri, $data);
    }

    public function delete(string $uri): TestResponse
    {
        return $this->send('DELETE', $uri, null);
    }

    /**
     * A JSON request body (`Content-Type: application/json`), for an API endpoint.
     *
     * @param array<array-key, mixed> $data
     */
    public function json(string $method, string $uri, array $data = []): TestResponse
    {
        $request = $this->request($method, $uri, [])
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::fromString((string) json_encode($data, \JSON_THROW_ON_ERROR)));

        return $this->dispatch($request);
    }

    /**
     * @param array<string, mixed>|null $form
     * @param array<string, mixed>      $query
     */
    private function send(string $method, string $uri, ?array $form, array $query = []): TestResponse
    {
        $request = $this->request($method, $uri, $query);

        if ($form !== null) {
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withParsedBody($form);
        }

        return $this->dispatch($request);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(string $method, string $uri, array $query): ServerRequest
    {
        $request = new ServerRequest($method, 'http://app.test' . $uri, ['Accept' => 'application/json', ...$this->headers])
            ->withAttribute(ServerRequestCreator::CLIENT_IP_ATTRIBUTE, $this->address);

        if ($query !== []) {
            $request = $request->withQueryParams($query);
        }

        if ($this->cookies !== []) {
            $request = $request->withCookieParams($this->cookies);
        }

        return $request;
    }

    private function dispatch(ServerRequest $request): TestResponse
    {
        $response = $this->kernel->handle($request);

        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (str_contains($header, 'Max-Age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return new TestResponse($response);
    }
}

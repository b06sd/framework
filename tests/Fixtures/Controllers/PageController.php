<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Controllers;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final readonly class PageController
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function show(ServerRequestInterface $request, int $id): ResponseInterface
    {
        return $this->text('page ' . $id . ' via ' . $request->getMethod());
    }

    public function list(int $page = 1): ResponseInterface
    {
        return $this->text('list ' . $page);
    }

    public function flag(bool $on): ResponseInterface
    {
        return $this->text($on ? 'on' : 'off');
    }

    public function echo(string $text): ResponseInterface
    {
        return $this->text($text);
    }

    public function bad(): string
    {
        return 'not a response';
    }

    public function boom(): never
    {
        throw new RuntimeException('secret database password');
    }

    private function text(string $body): ResponseInterface
    {
        $response = $this->responses->createResponse(200)->withHeader('Content-Type', 'text/plain');
        $response->getBody()->write($body);

        return $response;
    }
}

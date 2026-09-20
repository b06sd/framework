<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Error\ValidationException;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Tests\Fixtures\Validation\RegisterRequest;
use Trunk\Tests\Fixtures\Validation\SearchRequest;
use Trunk\Validation\Http\RequestValidator;

final readonly class SignupController
{
    public function __construct(private RequestValidator $requests, private ResponseBuilder $responses) {}

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $signup = $this->requests->validate(RegisterRequest::class, $request);

        if ($signup->email === 'taken@example.com') {
            throw ValidationException::field('email', 'Already registered.');
        }

        return $this->responses->json(['email' => $signup->email, 'plan' => $signup->plan->value, 'age' => $signup->age], 201);
    }

    public function search(ServerRequestInterface $request): ResponseInterface
    {
        $search = $this->requests->validate(SearchRequest::class, $request);

        return $this->responses->json(['page' => $search->page, 'q' => $search->q, 'archived' => $search->archived]);
    }
}

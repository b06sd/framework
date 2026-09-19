<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Controllers;

use Psr\Http\Message\ResponseInterface;
use Trunk\Mvc\Responder;

final readonly class UserPageController
{
    public function __construct(private Responder $responder) {}

    public function index(): ResponseInterface
    {
        return $this->responder->view('users/index', [
            'title' => 'Team <Core>',
            'users' => [['name' => 'Ada', 'email' => 'ada@trunk.dev'], ['name' => '<i>Bo</i>', 'email' => 'bo@trunk.dev']],
        ]);
    }

    public function api(int $id): ResponseInterface
    {
        return $this->responder->json(['id' => $id, 'name' => 'Ada']);
    }

    public function broken(): ResponseInterface
    {
        return $this->responder->view('broken', ['secret' => 'db-password-1234']);
    }

    public function search(string $q): ResponseInterface
    {
        return $this->responder->view('search', ['q' => $q]);
    }

    public function open(string $target): ResponseInterface
    {
        return $this->responder->redirect($target);
    }

    public function go(): ResponseInterface
    {
        return $this->responder->redirect('/users');
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Auth\Auth;
use Trunk\Auth\Csrf\Csrf;
use Trunk\Auth\Session\Session;
use Trunk\Http\Response\ResponseBuilder;

final readonly class AuthTestController
{
    public function __construct(private ResponseBuilder $responses, private Auth $auth, private Csrf $csrf, private Session $session) {}

    public function plain(): ResponseInterface
    {
        return $this->responses->json(['ok' => true]);
    }

    public function csrf(): ResponseInterface
    {
        return $this->responses->json(['token' => $this->csrf->token()]);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];
        $email = \is_string($body['email'] ?? null) ? $body['email'] : '';
        $password = \is_string($body['password'] ?? null) ? $body['password'] : '';
        $user = $this->auth->attempt($email, $password);

        return $user === null ? $this->responses->json(['error' => 'Invalid credentials.'], 401) : $this->responses->json(['id' => $user->authId(), 'next' => $this->auth->intended('/me')]);
    }

    public function logout(): ResponseInterface
    {
        $this->auth->logout();

        return $this->responses->json(['ok' => true]);
    }

    public function me(): ResponseInterface
    {
        return $this->responses->json(['id' => $this->auth->user()?->authId()]);
    }

    public function page(): ResponseInterface
    {
        return $this->responses->html('<h1>Private</h1>');
    }

    public function put(ServerRequestInterface $request, string $key, string $value): ResponseInterface
    {
        $this->session->put($key, $value);

        return $this->responses->json(['stored' => true]);
    }

    public function get(string $key): ResponseInterface
    {
        return $this->responses->json(['value' => $this->session->get($key)]);
    }

    public function flashSet(): ResponseInterface
    {
        $this->session->flash('notice', 'Saved');

        return $this->responses->json(['ok' => true]);
    }

    public function flashGet(): ResponseInterface
    {
        return $this->responses->json(['notice' => $this->session->flashed('notice')]);
    }

    public function form(): ResponseInterface
    {
        $token = htmlspecialchars($this->csrf->token(), \ENT_QUOTES);

        return $this->responses->html('<!doctype html><title>Sign in</title><h1>Sign in</h1><form method="post" action="/web/login-form"><input type="hidden" name="_csrf" value="' . $token . '"><input name="email" id="email"><input name="password" id="password" type="password"><button type="submit">Sign in</button></form><script>document.title += " " + (document.cookie.includes("session") ? "COOKIE-READABLE" : "cookie-hidden")</script>');
    }

    public function loginForm(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];
        $user = $this->auth->attempt(\is_string($body['email'] ?? null) ? $body['email'] : '', \is_string($body['password'] ?? null) ? $body['password'] : '');

        return $user === null ? $this->responses->html('<h1>Invalid credentials.</h1>', 401) : $this->responses->redirect($this->auth->intended('/web/secret'));
    }

    public function logoutForm(): ResponseInterface
    {
        $this->auth->logout();

        return $this->responses->redirect('/web/form');
    }

    public function secret(): ResponseInterface
    {
        return $this->responses->html('<!doctype html><title>Private</title><h1>Private area</h1><p id="who">user ' . htmlspecialchars((string) $this->auth->user()?->authId(), \ENT_QUOTES) . '</p><form method="post" action="/web/logout-form"><input type="hidden" name="_csrf" value="' . htmlspecialchars($this->csrf->token(), \ENT_QUOTES) . '"><button>Sign out</button></form>');
    }

    public function whoami(): ResponseInterface
    {
        return $this->responses->json(['id' => $this->auth->user()?->authId(), 'viaToken' => $this->auth->viaToken(), 'canRead' => $this->auth->tokenCan('read'), 'canWrite' => $this->auth->tokenCan('write')]);
    }
}

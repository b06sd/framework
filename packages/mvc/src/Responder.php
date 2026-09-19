<?php

declare(strict_types=1);

namespace Trunk\Mvc;

use Psr\Http\Message\ResponseInterface;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Tusk\Renderer;

/**
 * Response helpers for web controllers: everything ResponseBuilder offers, plus Tusk views.
 * Inject it like any other service; there is no base class and no global helper.
 *
 * @api
 */
final readonly class Responder
{
    public function __construct(
        private ResponseBuilder $responses,
        private Renderer $views,
    ) {}

    /**
     * Renders a Tusk template ("users/show" -> resources/views/users/show.tusk.php).
     *
     * @param array<string, mixed> $data
     */
    public function view(string $template, array $data = [], int $status = 200): ResponseInterface
    {
        return $this->responses->html($this->views->render($template, $data), $status);
    }

    /**
     * Sends HTML you have already made safe. Prefer `view()`, which escapes by default.
     */
    public function html(string $html, int $status = 200): ResponseInterface
    {
        return $this->responses->html($html, $status);
    }

    public function text(string $text, int $status = 200): ResponseInterface
    {
        return $this->responses->text($text, $status);
    }

    public function json(mixed $data, int $status = 200): ResponseInterface
    {
        return $this->responses->json($data, $status);
    }

    public function noContent(): ResponseInterface
    {
        return $this->responses->noContent();
    }

    public function redirect(string $path, int $status = 302): ResponseInterface
    {
        return $this->responses->redirect($path, $status);
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Response;

use PHPUnit\Framework\TestCase;
use Trunk\Http\Exception\UnsafeRedirectException;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Response\ResponseBuilder;

final class ResponseBuilderTest extends TestCase
{
    public function test_json_html_text_and_redirect_responses_are_built_without_a_view_engine(): void
    {
        // Arrange
        $responses = new ResponseBuilder(new HttpFactory());

        // Act
        $json = $responses->json(['ok' => true], 201);
        $text = $responses->text('plain');
        $redirect = $responses->redirect('/next', 303);

        // Assert
        self::assertSame(201, $json->getStatusCode());
        self::assertSame('application/json', $json->getHeaderLine('Content-Type'));
        self::assertSame('{"ok":true}', (string) $json->getBody());
        self::assertSame('text/plain; charset=utf-8', $text->getHeaderLine('Content-Type'));
        self::assertSame('/next', $redirect->getHeaderLine('Location'));
        self::assertSame('nosniff', $json->getHeaderLine('X-Content-Type-Options'));
    }

    public function test_absolute_redirects_are_refused(): void
    {
        // Arrange
        $responses = new ResponseBuilder(new HttpFactory());

        // Act & Assert
        $this->expectException(UnsafeRedirectException::class);
        $responses->redirect('https://evil.test/');
    }
}

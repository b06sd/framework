<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Error;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Error\ErrorContext;
use Trunk\Error\ExceptionHandler;
use Trunk\Http\Error\ErrorFormat;
use Trunk\Http\Error\ErrorNegotiator;
use Trunk\Http\Error\HtmlErrorRenderer;
use Trunk\Http\Error\JsonErrorRenderer;
use Trunk\Http\Error\TextErrorRenderer;
use Trunk\Http\Message\ServerRequest;

final class ErrorRenderingTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, string, ErrorFormat}>
     */
    public static function negotiation(): iterable
    {
        yield 'browser' => [['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'], 'auto', ErrorFormat::Html];
        yield 'api client' => [['Accept' => 'application/json'], 'auto', ErrorFormat::Json];
        yield 'json:api' => [['Accept' => 'application/vnd.api+json'], 'auto', ErrorFormat::Json];
        yield 'json preferred by q' => [['Accept' => 'text/html;q=0.5, application/json;q=0.9'], 'auto', ErrorFormat::Json];
        yield 'html preferred by order' => [['Accept' => 'text/html, application/json'], 'auto', ErrorFormat::Html];
        yield 'json excluded by q0' => [['Accept' => 'application/json;q=0, text/html'], 'auto', ErrorFormat::Html];
        yield 'curl default' => [['Accept' => '*/*'], 'auto', ErrorFormat::Text];
        yield 'no accept' => [[], 'auto', ErrorFormat::Text];
        yield 'json body' => [['Content-Type' => 'application/json; charset=utf-8'], 'auto', ErrorFormat::Json];
        yield 'ajax' => [['X-Requested-With' => 'XMLHttpRequest'], 'auto', ErrorFormat::Json];
        yield 'forced json' => [['Accept' => 'text/html'], 'json', ErrorFormat::Json];
        yield 'forced text' => [['Accept' => 'application/json'], 'text', ErrorFormat::Text];
        yield 'garbage header' => [['Accept' => str_repeat(';,', 2000) . 'application/json'], 'auto', ErrorFormat::Text];
        yield 'unknown forced value' => [['Accept' => 'application/json'], 'yaml', ErrorFormat::Json];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('negotiation')]
    public function test_the_format_follows_what_the_client_asked_for(array $headers, string $configured, ErrorFormat $expected): void
    {
        // Arrange
        $request = new ServerRequest('GET', '/');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // Act & Assert
        self::assertSame($expected, new ErrorNegotiator()->negotiate($request, $configured));
    }

    public function test_without_a_request_the_format_is_text(): void
    {
        // Arrange & Act & Assert
        self::assertSame(ErrorFormat::Text, new ErrorNegotiator()->negotiate(null));
    }

    public function test_the_development_page_escapes_everything_and_hides_secret_headers(): void
    {
        // Arrange
        $bearer = 'tok' . bin2hex(random_bytes(6));
        $session = 'sid' . bin2hex(random_bytes(6));
        $error = new RuntimeException('<script>alert("x")</script> & <img src=x onerror=alert(1)>');
        $report = new ExceptionHandler()->handle($error, new ErrorContext(requestId: 'req_"><b>', debug: true));
        $request = new ServerRequest('GET', 'http://trunk.dev/%3Csvg%20onload=alert(1)%3E')->withHeader('Authorization', 'Bearer ' . $bearer)->withHeader('Cookie', 'session=' . $session)->withHeader('X-Custom', '<i>');

        // Act
        $rendered = new HtmlErrorRenderer()->render($report, ErrorFormat::Html, $request, true);

        // Assert
        self::assertNotNull($rendered);
        self::assertStringNotContainsString('<script>alert', $rendered->body);
        self::assertStringNotContainsString('<img src=x', $rendered->body);
        self::assertStringNotContainsString('<svg onload', $rendered->body);
        self::assertStringNotContainsString('req_"><b>', $rendered->body);
        self::assertStringNotContainsString($bearer, $rendered->body);
        self::assertStringNotContainsString($session, $rendered->body);
        self::assertStringContainsString('&lt;script&gt;', $rendered->body);
        self::assertStringContainsString('[REDACTED]', $rendered->body);
        self::assertArrayHasKey('Content-Security-Policy', $rendered->headers);
    }

    public function test_renderers_only_answer_their_own_format_and_json_has_the_documented_shape(): void
    {
        // Arrange
        $report = new ExceptionHandler()->handle(new \Trunk\Error\ValidationException(['email' => ['is required']]), new ErrorContext(requestId: 'req_1'));

        // Act
        $json = new JsonErrorRenderer()->render($report, ErrorFormat::Json, null, false);

        // Assert
        self::assertNull(new JsonErrorRenderer()->render($report, ErrorFormat::Html, null, false));
        self::assertNull(new HtmlErrorRenderer()->render($report, ErrorFormat::Json, null, false));
        self::assertNotNull($json);
        self::assertSame('{"error":{"code":"VALIDATION_FAILED","message":"The given data was invalid.","requestId":"req_1","details":{"fields":{"email":["is required"]}}}}', $json->body);
        self::assertSame('The given data was invalid.', new TextErrorRenderer()->render($report, ErrorFormat::Text, null, false)->body);
    }

    public function test_production_renderers_ignore_debug_info_even_if_present(): void
    {
        // Arrange
        $report = new ExceptionHandler()->handle(new RuntimeException('internal detail'), new ErrorContext(debug: true));

        // Act
        $json = new JsonErrorRenderer()->render($report, ErrorFormat::Json, null, false);
        $html = new HtmlErrorRenderer()->render($report, ErrorFormat::Html, null, false);
        $text = new TextErrorRenderer()->render($report, ErrorFormat::Text, null, false);

        // Assert
        self::assertNotNull($json);
        self::assertNotNull($html);
        foreach ([$json->body, $html->body, $text->body] as $body) {
            self::assertStringNotContainsString('internal detail', $body);
            self::assertStringNotContainsString('.php', $body);
        }
    }
}

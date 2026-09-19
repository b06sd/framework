<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Mvc;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Http\Exception\UnsafeRedirectException;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Mvc\Responder;
use Trunk\Tests\Support\ViewHarness;

final class ResponderTest extends TestCase
{
    private ViewHarness $views;

    protected function setUp(): void
    {
        $this->views = new ViewHarness(['hello' => '<p>{{ name }}</p>']);
    }

    protected function tearDown(): void
    {
        $this->views->cleanUp();
    }

    public function test_view_renders_an_escaped_html_response(): void
    {
        // Arrange
        $responder = $this->responder();

        // Act
        $response = $responder->view('hello', ['name' => '<script>x</script>'], 201);

        // Assert
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('<p>&lt;script&gt;x&lt;/script&gt;</p>', (string) $response->getBody());
    }

    public function test_text_json_and_no_content_responses(): void
    {
        // Arrange
        $responder = $this->responder();

        // Act
        $text = $responder->text('plain');
        $json = $responder->json(['a' => 1, 'html' => '</script>']);
        $none = $responder->noContent();

        // Assert
        self::assertSame('text/plain; charset=utf-8', $text->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $json->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('<', (string) $json->getBody());
        self::assertStringNotContainsString('>', (string) $json->getBody());
        self::assertSame(['a' => 1, 'html' => '</script>'], json_decode((string) $json->getBody(), true));
        self::assertSame(204, $none->getStatusCode());
        self::assertSame('', (string) $none->getBody());
    }

    public function test_local_redirects_set_the_location_header(): void
    {
        // Arrange
        $responder = $this->responder();

        // Act
        $response = $responder->redirect('/users/5?tab=1', 303);

        // Assert
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/users/5?tab=1', $response->getHeaderLine('Location'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeTargets(): iterable
    {
        yield 'absolute url' => ['https://evil.test/'];
        yield 'scheme relative' => ['//evil.test/'];
        yield 'backslash trick' => ['/\\evil.test'];
        yield 'embedded backslash' => ['/a\\b'];
        yield 'relative' => ['users'];
        yield 'empty' => [''];
        yield 'header injection' => ["/ok\r\nSet-Cookie: a=b"];
        yield 'newline' => ["/ok\nX: y"];
        yield 'nul' => ["/ok\0"];
        yield 'space' => ['/a b'];
        yield 'javascript' => ['javascript:alert(1)'];
    }

    #[DataProvider('unsafeTargets')]
    public function test_redirects_to_anything_but_a_local_path_are_refused(string $target): void
    {
        // Arrange
        $responder = $this->responder();

        // Act & Assert
        $this->expectException(UnsafeRedirectException::class);
        $responder->redirect($target);
    }

    public function test_only_redirect_statuses_are_accepted(): void
    {
        // Arrange
        $responder = $this->responder();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $responder->redirect('/ok', 200);
    }

    private function responder(): Responder
    {
        return new Responder(new ResponseBuilder(new HttpFactory()), $this->views->renderer());
    }
}

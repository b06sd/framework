<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Response;

use PHPUnit\Framework\TestCase;
use Trunk\Http\Emitter\ResponseEmitter;
use Trunk\Http\Exception\UnsafeRedirectException;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Tests\Support\FakeSapi;

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

    public function test_a_download_is_an_attachment_sandboxed_with_a_safe_file_name(): void
    {
        // Arrange
        $responses = new ResponseBuilder(new HttpFactory());

        // Act
        $plain = $responses->download('a,b', 'report.csv', 'text/csv');
        $hostile = $responses->download('x', "inv\"oice\r\nSet-Cookie: a=1/../\\é.pdf", "text/html\r\nX-Evil: 1");

        // Assert
        self::assertSame('attachment; filename="report.csv"; filename*=UTF-8\'\'report.csv', $plain->getHeaderLine('Content-Disposition'));
        self::assertSame('text/csv', $plain->getHeaderLine('Content-Type'));
        self::assertSame("default-src 'none'; sandbox", $plain->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $plain->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('a,b', (string) $plain->getBody());
        self::assertSame('attachment; filename="invoiceSet-Cookie: a=1..__.pdf"; filename*=UTF-8\'\'invoiceSet-Cookie%3A%20a%3D1..%C3%A9.pdf', $hostile->getHeaderLine('Content-Disposition'), 'quotes, line breaks and slashes are dropped; non-ASCII is encoded');
        self::assertSame('application/octet-stream', $hostile->getHeaderLine('Content-Type'));
        self::assertFalse($hostile->hasHeader('Set-Cookie'));
    }

    public function test_a_download_streams_a_file_resource_with_its_length(): void
    {
        // Arrange: what Flysystem's readStream() returns
        $file = tempnam(sys_get_temp_dir(), 'trunk-dl');
        self::assertNotFalse($file);
        file_put_contents($file, str_repeat('z', 100_000));
        $resource = fopen($file, 'rb');
        self::assertNotFalse($resource);
        $sapi = new FakeSapi();

        // Act
        new ResponseEmitter($sapi)->emit(new ResponseBuilder(new HttpFactory())->download($resource, 'big.bin'));
        unlink($file);

        // Assert
        self::assertContains('header:Content-Length: 100000', $sapi->calls);
        self::assertGreaterThan(1, \count(array_filter($sapi->calls, static fn(string $c): bool => str_starts_with($c, 'write:'))), 'sent in chunks');
    }
}

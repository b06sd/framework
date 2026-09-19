<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Server;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Trunk\Http\Server\ServerRequestCreator;

final class ServerRequestCreatorTest extends TestCase
{
    public function test_a_form_post_is_translated_into_a_server_request(): void
    {
        // Arrange
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'Example.com:8443',
            'REQUEST_URI' => '/users?id=5',
            'SERVER_PROTOCOL' => 'HTTP/2.0',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=utf-8',
            'HTTP_X_CUSTOM_HEADER' => 'v',
        ];

        // Act
        $request = new ServerRequestCreator()->fromArrays($server, ['id' => '5'], ['name' => 'trunk'], ['sid' => 'abc']);

        // Assert
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://example.com:8443/users?id=5', (string) $request->getUri());
        self::assertSame('2.0', $request->getProtocolVersion());
        self::assertSame('v', $request->getHeaderLine('x-custom-header'));
        self::assertSame('application/x-www-form-urlencoded; charset=utf-8', $request->getHeaderLine('Content-Type'));
        self::assertSame(['id' => '5'], $request->getQueryParams());
        self::assertSame(['name' => 'trunk'], $request->getParsedBody());
        self::assertSame(['sid' => 'abc'], $request->getCookieParams());
        self::assertSame($server, $request->getServerParams());
    }

    public function test_post_data_is_ignored_for_non_form_content_types(): void
    {
        // Arrange
        $server = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'a.test', 'CONTENT_TYPE' => 'application/json'];

        // Act
        $request = new ServerRequestCreator()->fromArrays($server, [], ['x' => 'y']);

        // Assert
        self::assertNull($request->getParsedBody());
    }

    public function test_the_port_falls_back_to_server_port_and_https_off_means_http(): void
    {
        // Arrange
        $server = ['HTTP_HOST' => 'a.test', 'HTTPS' => 'off', 'SERVER_PORT' => '8080', 'REQUEST_URI' => '/'];

        // Act
        $request = new ServerRequestCreator()->fromArrays($server);

        // Assert
        self::assertSame('http://a.test:8080/', (string) $request->getUri());
    }

    public function test_absolute_form_targets_never_change_the_host(): void
    {
        // Arrange
        $server = ['HTTP_HOST' => 'good.test', 'REQUEST_URI' => 'http://evil.test/path?x=1'];

        // Act
        $request = new ServerRequestCreator()->fromArrays($server);

        // Assert
        self::assertSame('good.test', $request->getUri()->getHost());
        self::assertSame('/path', $request->getUri()->getPath());
        self::assertSame('x=1', $request->getUri()->getQuery());
    }

    public function test_forwarding_headers_and_httpoxy_are_not_trusted(): void
    {
        // Arrange
        $server = [
            'HTTP_HOST' => 'good.test',
            'HTTP_X_FORWARDED_HOST' => 'evil.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_PROXY' => 'http://attacker.test:8080',
            'REQUEST_URI' => '/',
        ];

        // Act
        $request = new ServerRequestCreator()->fromArrays($server);

        // Assert
        self::assertSame('http://good.test/', (string) $request->getUri());
        self::assertFalse($request->hasHeader('Proxy'));
    }

    public function test_malformed_host_headers_are_rejected(): void
    {
        // Arrange
        $server = ['HTTP_HOST' => 'good.test, evil.test'];

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        new ServerRequestCreator()->fromArrays($server);
    }

    public function test_uploaded_files_are_normalized_including_nested_structures(): void
    {
        // Arrange
        $files = [
            'avatar' => ['tmp_name' => '/tmp/a', 'size' => 10, 'error' => \UPLOAD_ERR_OK, 'name' => 'a.png', 'type' => 'image/png'],
            'docs' => [
                'tmp_name' => ['/tmp/1', '/tmp/2'],
                'size' => [1, 2],
                'error' => [\UPLOAD_ERR_OK, \UPLOAD_ERR_OK],
                'name' => ['one.txt', 'two.txt'],
                'type' => ['text/plain', 'text/plain'],
            ],
        ];

        // Act
        $uploaded = new ServerRequestCreator()->fromArrays(['HTTP_HOST' => 'a.test'], files: $files)->getUploadedFiles();

        // Assert
        self::assertInstanceOf(UploadedFileInterface::class, $uploaded['avatar']);
        self::assertSame('a.png', $uploaded['avatar']->getClientFilename());
        self::assertIsArray($uploaded['docs']);
        self::assertCount(2, $uploaded['docs']);
        self::assertInstanceOf(UploadedFileInterface::class, $uploaded['docs'][1]);
        self::assertSame('two.txt', $uploaded['docs'][1]->getClientFilename());
    }

    public function test_malformed_upload_structures_are_rejected(): void
    {
        // Arrange
        $files = ['bad' => 'not-an-array'];

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        new ServerRequestCreator()->fromArrays(['HTTP_HOST' => 'a.test'], files: $files);
    }
}

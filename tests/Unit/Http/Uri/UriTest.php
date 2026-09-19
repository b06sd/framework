<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Uri;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Http\Uri\Uri;

final class UriTest extends TestCase
{
    public function test_all_components_are_parsed_and_round_trip(): void
    {
        // Arrange
        $uri = new Uri('HTTPS://user:pass@Example.COM:8080/a/b?x=1&y=2#frag');

        // Act & Assert
        self::assertSame('https', $uri->getScheme());
        self::assertSame('user:pass', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8080, $uri->getPort());
        self::assertSame('/a/b', $uri->getPath());
        self::assertSame('x=1&y=2', $uri->getQuery());
        self::assertSame('frag', $uri->getFragment());
        self::assertSame('user:pass@example.com:8080', $uri->getAuthority());
        self::assertSame('https://user:pass@example.com:8080/a/b?x=1&y=2#frag', (string) $uri);
    }

    public function test_default_ports_are_omitted(): void
    {
        // Arrange
        $uri = new Uri('http://example.com:80/');

        // Act & Assert
        self::assertNull($uri->getPort());
        self::assertSame('http://example.com/', (string) $uri);
    }

    public function test_withers_return_new_instances(): void
    {
        // Arrange
        $uri = new Uri('http://example.com/');

        // Act
        $changed = $uri->withScheme('https')->withHost('trunk.dev')->withPort(444)->withPath('/x')->withQuery('a=b')->withFragment('f')->withUserInfo('u', 'p');

        // Assert
        self::assertSame('http://example.com/', (string) $uri);
        self::assertSame('https://u:p@trunk.dev:444/x?a=b#f', (string) $changed);
    }

    public function test_unsafe_characters_are_percent_encoded_without_double_encoding(): void
    {
        // Arrange
        $uri = new Uri();

        // Act
        $result = $uri->withPath('/caf' . "\u{e9}" . '/x%2Fy');

        // Assert
        self::assertSame('/caf%C3%A9/x%2Fy', $result->getPath());
    }

    public function test_a_path_can_never_become_an_authority(): void
    {
        // Arrange
        $uri = new Uri()->withPath('//evil.example/steal');

        // Act
        $string = (string) $uri;

        // Assert
        self::assertSame('/evil.example/steal', $string);
        self::assertSame('', new Uri($string)->getHost());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUris(): iterable
    {
        yield 'space' => ['http://exa mple.com/'];
        yield 'backslash' => ['http://example.com\\evil.com/'];
        yield 'crlf' => ["http://example.com/\r\nHost: evil"];
        yield 'nul' => ["http://example.com/\0"];
        yield 'bad port' => ['http://example.com:70000/'];
        yield 'bad scheme' => ['1http://example.com/'];
        yield 'bad host' => ['http://exa<mple.com/'];
    }

    #[DataProvider('invalidUris')]
    public function test_malformed_uris_are_rejected(string $input): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        new Uri($input);
    }

    public function test_ipv6_hosts_are_supported(): void
    {
        // Arrange
        $uri = new Uri('http://[::1]:8080/');

        // Act & Assert
        self::assertSame('[::1]', $uri->getHost());
        self::assertSame(8080, $uri->getPort());
    }

    public function test_a_host_containing_user_info_is_rejected(): void
    {
        // Arrange
        $uri = new Uri();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $uri->withHost('good.com@evil.com');
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Server;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Http\HttpModule;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\Http\Server\TrustedProxies;

final class TrustedProxiesTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function membership(): iterable
    {
        yield 'inside a v4 range' => [['10.0.0.0/8'], '10.200.3.4', true];
        yield 'outside a v4 range' => [['10.0.0.0/8'], '11.0.0.1', false];
        yield 'a single address' => [['192.168.1.5'], '192.168.1.5', true];
        yield 'a neighbouring address' => [['192.168.1.5'], '192.168.1.6', false];
        yield 'a non byte aligned prefix' => [['172.16.0.0/12'], '172.31.255.255', true];
        yield 'just past a non byte aligned prefix' => [['172.16.0.0/12'], '172.32.0.0', false];
        yield 'v6 range' => [['fd00::/8'], 'fd12:3456::1', true];
        yield 'outside a v6 range' => [['fd00::/8'], 'fe80::1', false];
        yield 'v4 never matches a v6 range' => [['::/0'], '10.0.0.1', false];
        yield 'zero prefix v4 trusts every v4 address' => [['0.0.0.0/0'], '8.8.8.8', true];
        yield 'nothing configured' => [[], '10.0.0.1', false];
        yield 'garbage peer' => [['10.0.0.0/8'], 'not-an-ip', false];
        yield 'empty peer' => [['10.0.0.0/8'], '', false];
    }

    /**
     * @param list<string> $cidrs
     */
    #[DataProvider('membership')]
    public function test_membership(array $cidrs, string $address, bool $expected): void
    {
        // Act & Assert
        self::assertSame($expected, new TrustedProxies($cidrs)->isTrusted($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'a hostname' => ['proxy.example'];
        yield 'a wildcard' => ['10.0.*'];
        yield 'a prefix too long for v4' => ['10.0.0.0/33'];
        yield 'a prefix too long for v6' => ['fd00::/129'];
        yield 'a negative prefix' => ['10.0.0.0/-1'];
        yield 'a non numeric prefix' => ['10.0.0.0/eight'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalid')]
    public function test_a_bad_entry_is_rejected_with_the_entry_named(string $cidr): void
    {
        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('http.trusted_proxies');
        new TrustedProxies([$cidr]);
    }

    public function test_configuration_must_be_a_list_of_strings_and_defaults_to_empty(): void
    {
        // Act & Assert
        self::assertTrue(TrustedProxies::fromConfiguration(new Configuration([]))->isEmpty());
        self::assertFalse(TrustedProxies::fromConfiguration(new Configuration(['http' => ['trusted_proxies' => ['10.0.0.0/8']]]))->isEmpty());
        $this->expectException(InvalidArgumentException::class);
        TrustedProxies::fromConfiguration(new Configuration(['http' => ['trusted_proxies' => '10.0.0.0/8']]));
    }

    public function test_the_build_refuses_an_invalid_proxy_entry_and_names_the_config_file(): void
    {
        // Arrange
        $manifest = new ModuleManifest([HttpModule::class]);
        $runtime = new Runtime(Environment::Production, false, sys_get_temp_dir());
        $context = new BuildContext($manifest, $runtime, new Configuration(['http' => ['trusted_proxies' => ['proxy.example']]]));

        // Act & Assert
        try {
            new HttpModule()->plan($context);
            self::fail('Expected the build to fail.');
        } catch (CompilationException $e) {
            self::assertStringContainsString('config/http.php: http.trusted_proxies: "proxy.example" is not an IP address or CIDR range.', $e->getMessage());
        }
    }

    public function test_an_untrusted_peer_cannot_change_scheme_host_port_or_address(): void
    {
        // Arrange
        $creator = new ServerRequestCreator(proxies: new TrustedProxies(['10.0.0.0/8']));

        // Act
        $request = $creator->fromArrays($this->server('203.0.113.9') + ['HTTP_X_FORWARDED_FOR' => '1.2.3.4']);

        // Assert
        self::assertSame('http://app.test/x', (string) $request->getUri());
        self::assertSame('203.0.113.9', $request->getAttribute('client_ip'));
    }

    public function test_a_trusted_proxy_sets_scheme_host_port_and_client_address(): void
    {
        // Arrange
        $creator = new ServerRequestCreator(proxies: new TrustedProxies(['10.0.0.0/8']));

        // Act
        $request = $creator->fromArrays($this->server('10.0.0.5') + ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'shop.example.com', 'HTTP_X_FORWARDED_PORT' => '8443', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']);

        // Assert
        self::assertSame('https://shop.example.com:8443/x', (string) $request->getUri());
        self::assertSame('198.51.100.7', $request->getAttribute('client_ip'));
    }

    public function test_a_client_spoofed_forwarded_for_entry_is_skipped_because_the_chain_is_read_from_the_right(): void
    {
        // Arrange
        $creator = new ServerRequestCreator(proxies: new TrustedProxies(['10.0.0.0/8']));

        // Act: the client sent 6.6.6.6; proxy 10.0.0.9 saw 198.51.100.7 and appended it; 10.0.0.5 is the peer
        $request = $creator->fromArrays($this->server('10.0.0.5') + ['HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 10.0.0.9']);

        // Assert
        self::assertSame('198.51.100.7', $request->getAttribute('client_ip'));
    }

    public function test_only_the_value_the_nearest_proxy_appended_is_used_for_scheme_and_host(): void
    {
        // Arrange
        $creator = new ServerRequestCreator(proxies: new TrustedProxies(['10.0.0.0/8']));

        // Act
        $request = $creator->fromArrays($this->server('10.0.0.5') + ['HTTP_X_FORWARDED_PROTO' => 'http, https', 'HTTP_X_FORWARDED_HOST' => 'evil.example, shop.example.com']);

        // Assert
        self::assertSame('https://shop.example.com/x', (string) $request->getUri());
    }

    public function test_malformed_forwarded_values_from_a_trusted_proxy_are_ignored_not_believed(): void
    {
        // Arrange
        $creator = new ServerRequestCreator(proxies: new TrustedProxies(['10.0.0.0/8']));

        // Act
        $request = $creator->fromArrays($this->server('10.0.0.5') + ['HTTP_X_FORWARDED_PROTO' => 'javascript', 'HTTP_X_FORWARDED_HOST' => 'a.test/../evil', 'HTTP_X_FORWARDED_PORT' => '99999', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip']);

        // Assert
        self::assertSame('http://app.test/x', (string) $request->getUri());
        self::assertSame('10.0.0.5', $request->getAttribute('client_ip'));
    }

    public function test_without_configured_proxies_nothing_is_ever_believed(): void
    {
        // Act
        $request = new ServerRequestCreator()->fromArrays($this->server('10.0.0.5') + ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);

        // Assert
        self::assertSame('http://app.test/x', (string) $request->getUri());
        self::assertSame('10.0.0.5', $request->getAttribute('client_ip'));
    }

    /**
     * @return array<string, string>
     */
    private function server(string $peer): array
    {
        return ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'app.test', 'REQUEST_URI' => '/x', 'REMOTE_ADDR' => $peer];
    }
}

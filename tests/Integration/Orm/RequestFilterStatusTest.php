<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Orm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Fixtures\Modules\OrmWebModule;
use Trunk\Tests\Support\KernelHarness;

/**
 * A filter or sort taken from a request that the entity map does not allow is the client's mistake: a
 * 400 with a generic body. A typo in the application's own query is a bug: a 500.
 */
final class RequestFilterStatusTest extends TestCase
{
    /** @var list<KernelHarness> */
    private array $harnesses = [];

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->cleanUp();
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'development' => [false];
        yield 'compiled' => [true];
    }

    #[DataProvider('modes')]
    public function test_a_filter_or_sort_the_map_does_not_allow_is_a_400_that_names_nothing(bool $compiled): void
    {
        // Arrange
        $kernel = $this->kernel($compiled);

        foreach (['/customers?passwordHash=x', '/customers?nope=1', '/customers?sort=passwordHash', '/customers?status[]=1&status[]=2&status[]=3&status[]=4&status[]=5&status[]=6&status[]=7&status[]=8&status[]=9&status[]=10&status[]=11&status[]=12&status[]=13&status[]=14&status[]=15&status[]=16&status[]=17&status[]=18&status[]=19&status[]=20&status[]=21&status[]=22&status[]=23&status[]=24&status[]=25&status[]=26&status[]=27&status[]=28&status[]=29&status[]=30&status[]=31&status[]=32&status[]=33&status[]=34&status[]=35&status[]=36&status[]=37&status[]=38&status[]=39&status[]=40&status[]=41&status[]=42&status[]=43&status[]=44&status[]=45&status[]=46&status[]=47&status[]=48&status[]=49&status[]=50&status[]=51&status[]=52&status[]=53&status[]=54&status[]=55&status[]=56&status[]=57&status[]=58&status[]=59&status[]=60&status[]=61&status[]=62&status[]=63&status[]=64&status[]=65'] as $uri) {
            // Act
            $response = $this->get($kernel, $uri);
            $body = (string) $response->getBody();

            // Assert
            self::assertSame(400, $response->getStatusCode(), $uri);
            self::assertStringContainsString('"code":"BAD_REQUEST"', $body, $uri);
            self::assertStringContainsString('"message":"The filter or sort is not valid."', $body, $uri);
            self::assertStringContainsString('"requestId"', $body, $uri);
            self::assertStringNotContainsString('passwordHash', $body, $uri);
            self::assertStringNotContainsString('Customer', $body, $uri);
        }
    }

    #[DataProvider('modes')]
    public function test_an_allowed_filter_still_works(bool $compiled): void
    {
        // Arrange
        $kernel = $this->kernel($compiled);

        // Act
        $response = $this->get($kernel, '/customers?name=Nobody&sort=-name');

        // Assert
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('{"count":0}', (string) $response->getBody());
    }

    #[DataProvider('modes')]
    public function test_a_typo_in_the_applications_own_query_stays_a_500_with_a_generic_body(bool $compiled): void
    {
        // Arrange
        $kernel = $this->kernel($compiled);

        // Act
        $response = $this->get($kernel, '/typo');
        $body = (string) $response->getBody();

        // Assert
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('"code":"INTERNAL_ERROR"', $body);
        self::assertStringNotContainsString('typo', $body);
    }

    private function get(HttpKernel $kernel, string $uri): \Psr\Http\Message\ResponseInterface
    {
        $query = [];
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);

        return $kernel->handle(new ServerRequest('GET', 'http://trunk.dev' . $uri, ['Accept' => 'application/json'])->withQueryParams($query));
    }

    private function kernel(bool $compiled): HttpKernel
    {
        $harness = new KernelHarness(modules: [HttpModule::class, OrmWebModule::class]);
        $this->harnesses[] = $harness;

        return $compiled ? $harness->compiled() : $harness->development();
    }
}

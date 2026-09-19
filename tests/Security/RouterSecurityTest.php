<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Router\Compiler\RouteCompiler;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Exception\InvalidRouteException;
use Trunk\Router\Matcher\Matcher;
use Trunk\Router\Matcher\MatchStatus;
use Trunk\Router\Pattern\PatternParser;
use Trunk\Tests\Fixtures\Controllers\UserController;

final class RouterSecurityTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function redosShapedConstraints(): iterable
    {
        yield 'nested plus' => ['([a-z]+)+'];
        yield 'nested star' => ['(a*)*'];
        yield 'alternation overlap' => ['(a|a)+'];
        yield 'alternation with quantifier' => ['(a|aa)+b'];
        yield 'backreference' => ['(a)\1'];
        yield 'lookbehind' => ['(?<=a)b'];
        yield 'possessive in group' => ['(?>a+)+'];
        yield 'unbounded lazy' => ['.*?'];
    }

    #[DataProvider('redosShapedConstraints')]
    public function test_constraints_that_could_backtrack_catastrophically_are_rejected(string $constraint): void
    {
        // Arrange
        $parser = new PatternParser();

        // Act & Assert
        $this->expectException(InvalidRouteException::class);
        $parser->parse('/x/{a:' . $constraint . '}');
    }

    public function test_pathological_inputs_finish_quickly_and_fail_closed(): void
    {
        // Arrange
        $matcher = $this->matcher();
        $inputs = [
            '/slug/' . str_repeat('a', 200000) . '!',
            '/uuid/' . str_repeat('a', 200000),
            '/users/' . str_repeat('%', 100000),
            '/' . str_repeat('/', 100000),
        ];

        // Act
        $start = hrtime(true);
        $statuses = array_map(static fn(string $path): MatchStatus => $matcher->match('GET', $path)->status, $inputs);
        $elapsed = (hrtime(true) - $start) / 1e9;

        // Assert
        self::assertSame([MatchStatus::NotFound, MatchStatus::NotFound, MatchStatus::Found, MatchStatus::NotFound], $statuses);
        self::assertLessThan(2.0, $elapsed);
    }

    public function test_decoded_parameters_containing_nul_bytes_or_invalid_utf8_are_never_returned(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act & Assert
        self::assertSame(MatchStatus::NotFound, $matcher->match('GET', '/users/a%00b')->status);
        self::assertSame(MatchStatus::NotFound, $matcher->match('GET', '/users/%FF%FE')->status);
        self::assertSame('caf' . "\u{e9}", $matcher->match('GET', '/users/caf%C3%A9')->params['id']);
    }

    public function test_dot_segments_are_data_not_navigation(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act
        $encoded = $matcher->match('GET', '/users/%2e%2e%2fadmin');
        $raw = $matcher->match('GET', '/users/../admin');

        // Assert
        self::assertSame(['id' => '../admin'], $encoded->params);
        self::assertSame(MatchStatus::NotFound, $raw->status);
    }

    public function test_hostile_handler_and_route_names_cannot_be_registered(): void
    {
        // Arrange
        $routes = new RouteCollector();
        $rejected = 0;
        $attempts = [
            static fn() => $routes->get('/x', "Foo'); system('id'); //"),
            static fn() => $routes->get('/x', [UserController::class, "show'); system('id'); //"]),
            static fn() => $routes->get('/x', [UserController::class, 'show'], "name'); system('id'); //"),
            static fn() => $routes->map(["GET'); system('id'); //"], '/x', [UserController::class, 'show']),
            static fn() => $routes->get("/x/{a:[a-z]+}'); system('id'); //", [UserController::class, 'show']),
        ];

        // Act
        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (InvalidRouteException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(\count($attempts), $rejected);
    }

    private function matcher(): Matcher
    {
        $r = new RouteCollector();
        $r->get('/slug/{s:slug}', [UserController::class, 'show']);
        $r->get('/any/{p:any}', [UserController::class, 'show']);
        $r->get('/users/{id}', [UserController::class, 'show']);
        $r->get('/uuid/{u:uuid}', [UserController::class, 'show']);

        return new Matcher(new RouteCompiler()->compile($r->routes()));
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Router\Matcher;

use PHPUnit\Framework\TestCase;
use Trunk\Http\Message\ServerRequest;
use Trunk\Router\Compiler\RouteCompiler;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Matcher\Matcher;
use Trunk\Router\Matcher\MatchStatus;
use Trunk\Tests\Fixtures\Controllers\UserController;

final class MatcherTest extends TestCase
{
    public function test_static_and_dynamic_routes_are_matched_with_params(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act
        $home = $matcher->match('GET', '/');
        $user = $matcher->match('GET', '/users/42');

        // Assert
        self::assertTrue($home->isFound());
        self::assertSame('home', $home->name);
        self::assertSame([UserController::class, 'show'], $user->handler);
        self::assertSame(['id' => '42'], $user->params);
        self::assertSame('user', $user->name);
    }

    public function test_static_routes_win_over_dynamic_ones(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act
        $result = $matcher->match('GET', '/users/me');

        // Assert
        self::assertSame([UserController::class, 'index'], $result->handler);
        self::assertSame([], $result->params);
    }

    public function test_constraints_are_enforced(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act
        $result = $matcher->match('GET', '/users/abc');

        // Assert
        self::assertSame(MatchStatus::NotFound, $result->status);
    }

    public function test_an_optional_segment_matches_with_and_without_a_value(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act
        $without = $matcher->match('GET', '/blog');
        $with = $matcher->match('GET', '/blog/3');

        // Assert
        self::assertSame([], $without->params);
        self::assertSame(['page' => '3'], $with->params);
    }

    public function test_catch_all_parameters_span_segments(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act
        $result = $matcher->match('GET', '/files/a/b/c.txt');

        // Assert
        self::assertSame(['path' => 'a/b/c.txt'], $result->params);
    }

    public function test_params_are_percent_decoded_but_encoded_slashes_do_not_split_segments(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act
        $encoded = $matcher->match('GET', '/tags/a%2Fb%20c');
        $split = $matcher->match('GET', '/tags/a/b');

        // Assert
        self::assertSame(['tag' => 'a/b c'], $encoded->params);
        self::assertSame(MatchStatus::NotFound, $split->status);
    }

    public function test_a_known_path_with_the_wrong_method_returns_the_allowed_methods(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act
        $result = $matcher->match('DELETE', '/users/7');
        $getOnly = $matcher->match('POST', '/users');

        // Assert
        self::assertSame(MatchStatus::MethodNotAllowed, $result->status);
        self::assertSame(['GET', 'HEAD', 'POST'], $result->allowedMethods);
        self::assertSame(['GET', 'HEAD'], $getOnly->allowedMethods);
    }

    public function test_head_falls_back_to_get(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act
        $result = $matcher->match('HEAD', '/users/5');

        // Assert
        self::assertTrue($result->isFound());
        self::assertSame(['id' => '5'], $result->params);
    }

    public function test_matching_is_exact_about_trailing_slashes_and_leading_slashes(): void
    {
        // Arrange
        $matcher = $this->matcher();

        // Act & Assert
        self::assertSame(MatchStatus::NotFound, $matcher->match('GET', '/users/')->status);
        self::assertSame(MatchStatus::NotFound, $matcher->match('GET', 'users')->status);
        self::assertSame(MatchStatus::NotFound, $matcher->match('GET', '')->status);
        self::assertSame(MatchStatus::NotFound, $matcher->match('GET', '/nothing')->status);
    }

    public function test_a_server_request_can_be_matched_directly(): void
    {
        // Arrange
        $matcher = $this->matcher();
        $request = new ServerRequest('GET', 'http://trunk.dev/users/9?x=1');

        // Act
        $result = $matcher->matchRequest($request);

        // Assert
        self::assertSame(['id' => '9'], $result->params);
    }
    private function matcher(): Matcher
    {
        $r = new RouteCollector();
        $r->get('/', [UserController::class, 'index'], 'home');
        $r->get('/users', [UserController::class, 'index']);
        $r->get('/users/me', [UserController::class, 'index']);
        $r->get('/users/{id:int}', [UserController::class, 'show'], 'user');
        $r->post('/users/{id:int}', [UserController::class, 'show']);
        $r->get('/blog/{page?}', [UserController::class, 'index']);
        $r->get('/files/{path:any}', [UserController::class, 'show']);
        $r->get('/tags/{tag}', [UserController::class, 'show']);

        return new Matcher(new RouteCompiler()->compile($r->routes()));
    }
}

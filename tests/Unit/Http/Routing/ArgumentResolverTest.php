<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Routing\ArgumentResolver;

final class ArgumentResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function validValues(): iterable
    {
        yield 'int' => ['int', '42', 42];
        yield 'negative int' => ['int', '-7', -7];
        yield 'float' => ['float', '1.5', 1.5];
        yield 'bool true' => ['bool', 'true', true];
        yield 'bool one' => ['bool', '1', true];
        yield 'bool false' => ['bool', 'FALSE', false];
        yield 'string' => ['string', 'a b', 'a b'];
    }

    #[DataProvider('validValues')]
    public function test_route_parameters_are_coerced_to_their_declared_type(string $type, string $raw, mixed $expected): void
    {
        // Arrange
        $resolver = new ArgumentResolver();

        // Act
        $result = $resolver->resolve([self::param($type)], new ServerRequest('GET', '/'), ['p' => $raw]);

        // Assert
        self::assertSame([$expected], $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'int letters' => ['int', 'abc'];
        yield 'int overflow' => ['int', '99999999999999999999'];
        yield 'int decimal' => ['int', '1.5'];
        yield 'float letters' => ['float', '1e5x'];
        yield 'float exponent' => ['float', '1e5'];
        yield 'bool word' => ['bool', 'yes'];
    }

    #[DataProvider('invalidValues')]
    public function test_values_that_do_not_fit_their_type_are_a_404(string $type, string $raw): void
    {
        // Arrange
        $resolver = new ArgumentResolver();

        // Act & Assert
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $resolver->resolve([self::param($type)], new ServerRequest('GET', '/'), ['p' => $raw]);
    }

    public function test_request_defaults_and_missing_optional_parameters_are_supplied(): void
    {
        // Arrange
        $request = new ServerRequest('GET', '/');
        $plan = [
            ['kind' => 'request', 'name' => 'r', 'type' => 'request', 'nullable' => false, 'hasDefault' => false, 'default' => null],
            ['kind' => 'default', 'name' => 'd', 'type' => 'default', 'nullable' => true, 'hasDefault' => true, 'default' => 'x'],
            self::param('int', hasDefault: true, default: 1),
            self::param('string', nullable: true),
        ];

        // Act
        $result = new ArgumentResolver()->resolve($plan, $request, []);

        // Assert
        self::assertSame([$request, 'x', 1, null], $result);
    }

    public function test_a_missing_required_parameter_is_a_404(): void
    {
        // Arrange
        $resolver = new ArgumentResolver();

        // Act & Assert
        $this->expectException(HttpException::class);
        $resolver->resolve([self::param('int')], new ServerRequest('GET', '/'), []);
    }
    /**
     * @return array{kind: string, name: string, type: string, nullable: bool, hasDefault: bool, default: scalar|null}
     */
    private static function param(string $type, bool $nullable = false, bool $hasDefault = false, int|string|null $default = null): array
    {
        return ['kind' => 'param', 'name' => 'p', 'type' => $type, 'nullable' => $nullable, 'hasDefault' => $hasDefault, 'default' => $default];
    }
}

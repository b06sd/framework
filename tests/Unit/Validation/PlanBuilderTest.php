<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Tests\Fixtures\Validation\Broken\BadPattern;
use Trunk\Tests\Fixtures\Validation\Broken\IterableField;
use Trunk\Tests\Fixtures\Validation\Broken\ListOfString;
use Trunk\Tests\Fixtures\Validation\Broken\MixedField;
use Trunk\Tests\Fixtures\Validation\Broken\NoConstructor;
use Trunk\Tests\Fixtures\Validation\Broken\PureEnumField;
use Trunk\Tests\Fixtures\Validation\Broken\UnionField;
use Trunk\Tests\Fixtures\Validation\Broken\Untyped;
use Trunk\Tests\Fixtures\Validation\Plan;
use Trunk\Tests\Fixtures\Validation\RegisterRequest;
use Trunk\Validation\Compiler\PlanBuilder;
use Trunk\Validation\Source;

final class PlanBuilderTest extends TestCase
{
    public function test_a_request_class_is_read_into_typed_fields_rules_and_defaults(): void
    {
        // Arrange
        $builder = new PlanBuilder();

        // Act
        $plan = $builder->plan(RegisterRequest::class);
        $fields = array_column($plan->fields, null, 'name');

        // Assert
        self::assertSame('string', $fields['email']->kind);
        self::assertTrue($fields['age']->nullable);
        self::assertSame('enum', $fields['plan']->kind);
        self::assertSame(Plan::Free, $fields['plan']->default);
        self::assertTrue($fields['password']->sensitive);
        self::assertSame('array', $fields['shipTo']->kind);
        self::assertSame('object', $fields['address']->kind);
        self::assertNull($plan->source);
    }

    public function test_all_follows_nested_request_classes(): void
    {
        // Arrange
        $builder = new PlanBuilder();

        // Act
        $plans = $builder->all([RegisterRequest::class]);

        // Assert
        self::assertSame([RegisterRequest::class, 'Trunk\Tests\Fixtures\Validation\Address'], array_keys($plans));
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('mistakes')]
    public function test_a_mistake_is_reported_with_the_field_and_a_fix(string $class, string $expected): void
    {
        // Arrange
        $builder = new PlanBuilder();

        // Act
        try {
            $builder->plan($class);
            self::fail('expected a CompilationException');
        } catch (CompilationException $e) {
            // Assert
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function mistakes(): iterable
    {
        yield 'untyped' => [Untyped::class, 'needs one declared type'];
        yield 'mixed' => [MixedField::class, 'cannot be validated'];
        yield 'two-type union' => [UnionField::class, 'needs one declared type'];
        yield 'iterable' => [IterableField::class, 'cannot be validated'];
        yield 'pure enum' => [PureEnumField::class, 'must be a backed enum'];
        yield 'list of on a string' => [ListOfString::class, '#[ListOf] belongs on an array parameter'];
        yield 'no constructor' => [NoConstructor::class, 'needs a public constructor'];
        yield 'bad pattern' => [BadPattern::class, '#[Pattern] is not valid'];
    }

    public function test_a_missing_class_is_a_build_error_not_a_silent_skip(): void
    {
        // Arrange
        $builder = new PlanBuilder();

        // Act
        try {
            $builder->all(['App\\Requests\\Nope']);
            self::fail('expected a CompilationException');
        } catch (CompilationException $e) {
            // Assert
            self::assertStringContainsString('App\\Requests\\Nope does not exist', $e->getMessage());
        }
    }

    public function test_from_on_the_class_sets_the_source(): void
    {
        // Arrange
        $builder = new PlanBuilder();

        // Act
        $plan = $builder->plan(\Trunk\Tests\Fixtures\Validation\SearchRequest::class);

        // Assert
        self::assertSame(Source::Query, $plan->source);
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\OpenApi;

use PHPUnit\Framework\TestCase;
use Trunk\OpenApi\Generator\SchemaBuilder;
use Trunk\Tests\Fixtures\OpenApi\BaseView;
use Trunk\Tests\Fixtures\OpenApi\ExtendedView;

final class SchemaBuilderTest extends TestCase
{
    public function test_relative_property_types_are_resolved_in_their_declaring_class(): void
    {
        // Arrange
        $builder = new SchemaBuilder();

        // Act
        $reference = $builder->output(ExtendedView::class);
        $components = $builder->components();

        // Assert
        self::assertSame(['$ref' => '#/components/schemas/ExtendedView'], $builder->resolve($reference));
        self::assertSame(['BaseView', 'ExtendedView'], array_keys($components));
        $properties = $components['ExtendedView']['properties'];
        $baseProperties = $components['BaseView']['properties'];
        self::assertIsArray($properties);
        self::assertIsArray($baseProperties);
        self::assertSame(['$ref' => '#/components/schemas/ExtendedView'], $properties['next']);
        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/BaseView'], ['type' => 'null']]],
            $properties['parent'],
        );
        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/BaseView'], ['type' => 'null']]],
            $properties['previous'],
        );
        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/BaseView'], ['type' => 'null']]],
            $baseProperties['previous'],
        );
        self::assertSame($components, $builder->components());
        self::assertSame(['$ref' => '#/components/schemas/BaseView'], $builder->resolve($builder->output(BaseView::class)));
    }
}

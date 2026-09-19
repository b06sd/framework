<?php

declare(strict_types=1);

namespace Trunk\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Trunk\Tests\Support\PublicApi;

/**
 * The public API policy (docs/API.md), enforced: what is `@api` is small, stable in shape, and never
 * leaks an unmarked type; everything else is internal by default.
 */
final class PublicApiTest extends TestCase
{
    public function test_an_api_type_is_an_interface_an_enum_or_a_final_class_never_a_base_class(): void
    {
        // Arrange
        $violations = [];

        // Act
        foreach (new PublicApi()->types() as $class => $type) {
            if ($type['api'] && $type['kind'] === 'class' && !new ReflectionClass($class)->isFinal()) {
                $violations[] = $class . ' is @api but not final';
            }
        }

        // Assert
        self::assertSame([], $violations);
    }

    public function test_abstract_classes_are_marked_internal_and_no_type_is_both_api_and_internal(): void
    {
        // Arrange
        $violations = [];

        // Act
        foreach (new PublicApi()->types() as $class => $type) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() && !$reflection->isInterface() && !$type['internal']) {
                $violations[] = $class . ' is abstract but not marked @internal (the rule is: no base classes to extend)';
            }

            if ($type['api'] && $type['internal']) {
                $violations[] = $class . ' is both @api and @internal';
            }
        }

        // Assert
        self::assertSame([], $violations);
    }

    public function test_an_api_signature_never_exposes_a_type_that_is_not_api(): void
    {
        // Arrange
        $api = new PublicApi();
        $types = $api->types();
        $violations = [];

        // Act
        foreach ($types as $class => $type) {
            if (!$type['api']) {
                continue;
            }

            foreach ($api->exposedTypes($class) as $use) {
                $used = trim(substr($use, (int) strrpos($use, ' ')));

                if (!($types[$used]['api'] ?? false)) {
                    $violations[] = $use . ' (not @api; mark it @api, or tag the method @internal)';
                }
            }
        }

        // Assert
        self::assertSame([], $violations);
    }

    public function test_the_api_list_in_the_docs_is_up_to_date(): void
    {
        // Arrange
        $root = \dirname(__DIR__, 2);
        $expected = self::listing(new PublicApi()->types());

        // Act
        $actual = is_file($root . '/docs/API.md') ? (string) file_get_contents($root . '/docs/API.md') : '';

        // Assert
        self::assertStringContainsString($expected, $actual, 'docs/API.md is out of date. Regenerate the list with: php tools/api-list.php --write');
    }

    /**
     * @param array<class-string, array{file: string, api: bool, internal: bool, kind: string}> $types
     */
    public static function listing(array $types): string
    {
        $packages = ['Http', 'Router', 'Tusk', 'Mvc', 'Console', 'Cache', 'Database', 'Orm', 'Queue', 'Telemetry', 'Auth'];
        $groups = [];

        foreach ($types as $class => $type) {
            if ($type['api']) {
                $segment = explode('\\', $class)[1];
                $groups[\in_array($segment, $packages, true) ? $segment : 'Core'][] = '- `' . $class . '` (' . $type['kind'] . ')';
            }
        }

        $order = ['Core', ...$packages];
        $lines = ['<!-- api-list:start -->'];

        foreach ($order as $group) {
            if (isset($groups[$group])) {
                array_push($lines, '', '### ' . $group, '', ...$groups[$group]);
            }
        }

        $lines[] = '';
        $lines[] = '<!-- api-list:end -->';

        return implode("\n", $lines);
    }
}

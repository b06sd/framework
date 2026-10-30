<?php

declare(strict_types=1);

namespace Trunk\Http\Routing;

use Trunk\Router\Compiler\RouteTable;
use Trunk\Router\Definition\ArgumentKind;

/**
 * Checks that `#[Body]` and `#[Query]` parameters can be bound: they need the validation capability.
 * Used by the build and by development startup, so the fix is shown before any request arrives.
 */
final class RouteInputValidator
{
    private const string VALIDATION_MODULE = 'Trunk\\Validation\\ValidationModule';

    /**
     * @param list<string> $modules enabled module classes
     *
     * @return list<string>
     */
    public function errors(RouteTable $table, array $modules): array
    {
        if (\in_array(self::VALIDATION_MODULE, $modules, true)) {
            return [];
        }

        $errors = [];

        foreach ($table->routes as $route) {
            foreach ($route['args'] as $argument) {
                if ($argument['kind'] === ArgumentKind::Body->value || $argument['kind'] === ArgumentKind::Query->value) {
                    $errors[] = \sprintf('Route %s: parameter $%s is #[%s], which needs the validation capability. Fix: trunk package:install validation', $route['pattern'], $argument['name'], ucfirst($argument['kind']));
                }
            }
        }

        return $errors;
    }
}

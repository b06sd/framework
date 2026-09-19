<?php

declare(strict_types=1);

namespace Trunk\Http\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Trunk\Http\Exception\HttpException;
use Trunk\Router\Definition\ArgumentKind;
use Trunk\Router\Definition\ArgumentPlanner;

/**
 * Executes a compile-time argument plan. No reflection: it only maps plan entries to values and
 * coerces route parameters strictly. Values that do not fit their declared type are a 404.
 *
 * @phpstan-import-type ArgumentPlan from ArgumentPlanner
 */
final class ArgumentResolver
{
    /**
     * @param list<ArgumentPlan>    $plan
     * @param array<string, string> $params
     *
     * @return list<mixed>
     */
    public function resolve(array $plan, ServerRequestInterface $request, array $params): array
    {
        $arguments = [];

        foreach ($plan as $argument) {
            $arguments[] = match ($argument['kind']) {
                ArgumentKind::Request->value => $request,
                ArgumentKind::Default->value => $argument['default'],
                default => $this->param($argument, $params),
            };
        }

        return $arguments;
    }

    /**
     * @param ArgumentPlan          $argument
     * @param array<string, string> $params
     */
    private function param(array $argument, array $params): mixed
    {
        if (!isset($params[$argument['name']])) {
            if ($argument['hasDefault']) {
                return $argument['default'];
            }

            return $argument['nullable'] ? null : throw HttpException::notFound('Missing route parameter.');
        }

        $raw = $params[$argument['name']];

        return match ($argument['type']) {
            'int' => preg_match('/^-?\d{1,18}$/D', $raw) === 1 ? (int) $raw : throw HttpException::notFound('Invalid integer parameter.'),
            'float' => is_numeric($raw) && preg_match('/^-?\d+(?:\.\d+)?$/D', $raw) === 1 ? (float) $raw : throw HttpException::notFound('Invalid float parameter.'),
            'bool' => match (strtolower($raw)) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => throw HttpException::notFound('Invalid boolean parameter.'),
            },
            default => $raw,
        };
    }
}

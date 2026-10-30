<?php

declare(strict_types=1);

namespace Trunk\Http\Routing;

use LogicException;
use Psr\Container\ContainerInterface;
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
     * @param ContainerInterface|null $container where the `InputBinder` for #[Body] and #[Query] arguments comes from
     *
     * @return list<mixed>
     */
    public function resolve(array $plan, ServerRequestInterface $request, array $params, ?ContainerInterface $container = null): array
    {
        $arguments = [];

        foreach ($plan as $argument) {
            $arguments[] = match ($argument['kind']) {
                ArgumentKind::Request->value => $request,
                ArgumentKind::Default->value => $argument['default'],
                ArgumentKind::Body->value, ArgumentKind::Query->value => $this->input($argument, $request, $container),
                default => $this->param($argument, $params),
            };
        }

        return $arguments;
    }

    /**
     * @param ArgumentPlan $argument
     */
    private function input(array $argument, ServerRequestInterface $request, ?ContainerInterface $container): object
    {
        $binder = $container?->get(InputBinder::class);

        if (!$binder instanceof InputBinder || !class_exists($argument['type'])) {
            throw new LogicException(\sprintf('Parameter $%s is #[Body] or #[Query], which needs the validation capability: trunk package:install validation', $argument['name']));
        }

        return $binder->bind($argument['type'], $request, $argument['kind'] === ArgumentKind::Query->value);
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

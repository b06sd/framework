<?php

declare(strict_types=1);

namespace Trunk\Router\Matcher;

use Trunk\Router\Definition\ArgumentPlanner;

/**
 * @phpstan-import-type ArgumentPlan from ArgumentPlanner
 */
final readonly class MatchResult
{
    /**
     * @param array{string, string}|null $handler
     * @param array<string, string>      $params
     * @param list<string>               $allowedMethods
     * @param list<ArgumentPlan>         $arguments
     * @param list<string>         $middleware
     */
    private function __construct(
        public MatchStatus $status,
        public ?int $routeId = null,
        public ?array $handler = null,
        public ?string $name = null,
        public array $params = [],
        public array $allowedMethods = [],
        public array $arguments = [],
        public array $middleware = [],
    ) {}

    /**
     * @param array{string, string} $handler
     * @param array<string, string> $params
     * @param list<ArgumentPlan>    $arguments
     * @param list<string>    $middleware
     */
    public static function found(int $routeId, array $handler, ?string $name, array $params, array $arguments = [], array $middleware = []): self
    {
        return new self(MatchStatus::Found, $routeId, $handler, $name, $params, arguments: $arguments, middleware: $middleware);
    }

    public static function notFound(): self
    {
        return new self(MatchStatus::NotFound);
    }

    /**
     * @param non-empty-list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(MatchStatus::MethodNotAllowed, allowedMethods: $allowedMethods);
    }

    public function isFound(): bool
    {
        return $this->status === MatchStatus::Found;
    }
}

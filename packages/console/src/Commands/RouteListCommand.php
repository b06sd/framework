<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Project\Project;
use Trunk\Router\Compiler\RouteCompiler;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Router\Exception\RouteCompilationException;

final readonly class RouteListCommand implements Command
{
    public function __construct(private Project $project) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('route:list', 'List every route, its handler and how arguments are bound');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $providers = [];

        foreach ($this->project->modules as $class) {
            $module = new $class();

            if ($module instanceof RouteProvider) {
                $providers[] = $module;
            }
        }

        if ($providers === []) {
            $output->warning('No module provides routes. (Is this an API or web application?)');

            return 0;
        }

        try {
            $table = new RouteCompiler()->compileProviders($providers);
        } catch (RouteCompilationException $e) {
            throw new CommandFailedException(implode("\n  ", $e->errors));
        }

        $rows = [];

        foreach ($table->routes as $route) {
            $arguments = array_map(static fn(array $a): string => $a['kind'] === 'request' ? 'request' : ($a['kind'] === 'param' ? $a['name'] . ':' . $a['type'] : $a['name'] . '=default'), $route['args']);
            $rows[] = [implode('|', $route['methods']), $route['pattern'], $route['name'] ?? '-', $route['handler'][0] . '::' . $route['handler'][1], implode(', ', $arguments), implode(', ', array_map(static fn(string $m): string => substr(strrchr('\\' . $m, '\\') ?: $m, 1), $route['middleware']))];
        }

        $output->table(['Method', 'Pattern', 'Name', 'Handler', 'Arguments', 'Middleware'], $rows);

        return 0;
    }
}

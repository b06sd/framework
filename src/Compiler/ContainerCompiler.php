<?php

declare(strict_types=1);

namespace Trunk\Compiler;

use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\Autowirer;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\AliasDefinition;
use Trunk\Container\Definition\Argument;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Definition\Definition;
use Trunk\Container\Definition\FactoryDefinition;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\ServiceDefinition;
use Trunk\Container\Definition\TaggedReference;
use Trunk\Container\Definition\Value;
use Trunk\Container\Exception\ContainerException;
use Trunk\Container\Lifetime;
use Trunk\Container\ResolutionHints;
use Trunk\Foundation\Configuration;

/**
 * Build-time only. Validates a builder's graph and renders it as a generated container class.
 * Output is assembled from validated identifiers and `var_export`ed literals; nothing is evaluated.
 *
 * Beyond explicit bindings it wires every concrete class reachable from the roots through
 * constructor types, and rejects: unresolvable ids (with a path and a fix), cycles, and singletons
 * that would capture scoped services.
 */
final class ContainerCompiler
{
    public const string NAMESPACE = 'Trunk\\Compiled';

    /**
     * @param list<string> $modules     module classes the container is compiled from
     * @param list<string> $externalIds ids supplied at runtime instead of compiled (e.g. Runtime)
     * @param list<string> $roots       ids the application resolves directly (controllers, middleware)
     * @param list<string> $scopedIds   ids provided per scope at runtime (e.g. the request)
     *
     * @throws CompilationException
     */
    public function compile(ContainerBuilder $builder, array $modules, array $externalIds, string $className = 'AppContainer', array $roots = [], array $scopedIds = []): string
    {
        return $this->plan($builder, $modules, $externalIds, $className, $roots, $scopedIds)->source;
    }

    /**
     * @param list<string> $modules
     * @param list<string> $externalIds
     * @param list<string> $roots
     * @param list<string> $scopedIds
     *
     * @throws CompilationException
     */
    public function plan(ContainerBuilder $builder, array $modules, array $externalIds, string $className = 'AppContainer', array $roots = [], array $scopedIds = []): ContainerPlan
    {
        $scopedIds = array_values(array_unique([...$scopedIds, ...$builder->scopedProvided()]));
        $errors = [];

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $className) !== 1) {
            $errors[] = \sprintf('"%s" is not a valid class name for the compiled container.', $className);
        }

        $closureIds = $builder->closureIds();

        foreach ($closureIds as $id) {
            $errors[] = \sprintf('"%s" is bound to a closure and cannot be compiled; use service(), factory(), alias() or autowire().', $id);
        }

        foreach ($externalIds as $id) {
            if ($builder->has($id)) {
                $errors[] = \sprintf('"%s" is provided at runtime and must not be bound by a module.', $id);
            }
        }

        $definitions = $builder->definitions();
        $tags = $builder->tags();
        $known = array_fill_keys([...array_map(\strval(...), array_keys($definitions)), ...$closureIds, ...$externalIds, ...$scopedIds], true);
        $auto = [];

        [$definitions, $auto, $resolutionErrors] = $this->resolveGraph($definitions, $tags, $roots, $known, $externalIds);
        $errors = [...$errors, ...$resolutionErrors];
        $known = array_fill_keys([...array_map(\strval(...), array_keys($definitions)), ...$closureIds, ...$externalIds, ...$scopedIds], true);

        $graph = $this->graph($definitions, $tags, $known, $externalIds);
        $errors = [...$errors, ...$this->cycles($graph), ...$this->captives($definitions, $graph, $scopedIds)];

        if ($errors !== []) {
            throw new CompilationException(array_values(array_unique($errors)));
        }

        return new ContainerPlan(
            $this->render($definitions, $tags, $modules, $className),
            [...array_map(\strval(...), array_keys($definitions)), ...$externalIds],
            $auto,
        );
    }

    /**
     * Auto-registers concrete classes reachable from the roots and reports everything unresolvable.
     *
     * @param array<string, Definition>   $definitions
     * @param array<string, list<string>> $tags
     * @param list<string>                $roots
     * @param array<string, true>         $known
     * @param list<string>                $externalIds
     *
     * @return array{array<string, Definition>, list<string>, list<string>} definitions, auto-registered ids, errors
     */
    private function resolveGraph(array $definitions, array $tags, array $roots, array $known, array $externalIds): array
    {
        $hints = new ResolutionHints();
        $autowirer = new Autowirer();
        $errors = [];
        $auto = [];
        $parents = [];
        $queue = [];
        $rootSet = array_fill_keys($roots, true);

        foreach ($roots as $root) {
            $parents[$root] ??= [null, null];
            $queue[] = $root;
        }

        foreach ($tags as $members) {
            foreach ($members as $member) {
                $parents[$member] ??= [null, null];
                $queue[] = $member;
            }
        }

        foreach ($definitions as $id => $definition) {
            foreach ($this->references($definition, $tags, $externalIds, $errors) as [$reference, $neededBy]) {
                $parents[$reference] ??= [(string) $id, $neededBy];
                $queue[] = $reference;
            }
        }

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($known[$id])) {
                continue;
            }

            $known[$id] = true;
            [$parent, $neededBy] = $parents[$id] ?? [null, null];

            if (!$hints->isConcrete($id)) {
                $errors[] = $hints->unresolved($id, $this->path($id, $parents), $neededBy);

                continue;
            }

            try {
                // Roots (controllers, middleware) are resolved per request, so they default to scoped; their
                // dependencies default to singleton.
                $definition = $autowirer->definitionFor($id, isset($rootSet[$id]) ? Lifetime::Scoped : Lifetime::Singleton);
            } catch (ContainerException $e) {
                $errors[] = $e->getMessage() . ' Path: ' . implode(' -> ', $this->path($id, $parents)) . '.';

                continue;
            }

            $definitions[$id] = $definition;
            $auto[] = $id;

            foreach ($this->references($definition, $tags, $externalIds, $errors) as [$reference, $reason]) {
                $parents[$reference] ??= [$id, $reason];
                $queue[] = $reference;
            }
        }

        return [$definitions, $auto, $errors];
    }

    /**
     * @param array<string, array{string|null, string|null}> $parents
     *
     * @return list<string>
     */
    private function path(string $id, array $parents): array
    {
        $path = [$id];
        $current = $id;

        while (($parent = $parents[$current][0] ?? null) !== null && !\in_array($parent, $path, true)) {
            array_unshift($path, $parent);
            $current = $parent;
        }

        return $path;
    }

    /**
     * Ids a definition depends on, with the constructor parameter that needs each (for error messages).
     *
     * @param array<string, list<string>> $tags
     * @param list<string>                $externalIds
     * @param list<string>                $errors
     *
     * @return list<array{string, string|null}>
     */
    private function references(Definition $definition, array $tags, array $externalIds, array &$errors): array
    {
        if ($definition instanceof AliasDefinition) {
            return [[$definition->target, null]];
        }

        if (!$definition instanceof ServiceDefinition && !$definition instanceof FactoryDefinition) {
            return [];
        }

        $references = $definition instanceof FactoryDefinition ? [[$definition->factory, null]] : [];

        foreach ($definition->arguments as $argument) {
            if ($argument instanceof Reference) {
                $references[] = [$argument->id, $argument->parameter];
            } elseif ($argument instanceof TaggedReference) {
                foreach ($tags[$argument->tag] ?? [] as $member) {
                    $references[] = [$member, null];
                }
            } elseif ($argument instanceof ConfigValue) {
                if (!\in_array(Configuration::class, $externalIds, true)) {
                    $errors[] = \sprintf('"%s" uses ConfigValue("%s") but Configuration is not provided at runtime.', $definition->id, $argument->key);
                }
            }
        }

        return $references;
    }

    /**
     * @param array<string, Definition>   $definitions
     * @param array<string, list<string>> $tags
     * @param array<string, true>         $known
     * @param list<string>                $externalIds
     *
     * @return array<string, list<string>>
     */
    private function graph(array $definitions, array $tags, array $known, array $externalIds): array
    {
        $graph = [];
        $ignored = [];

        foreach ($definitions as $id => $definition) {
            $graph[(string) $id] = [];

            foreach ($this->references($definition, $tags, $externalIds, $ignored) as [$reference]) {
                if (isset($known[$reference])) {
                    $graph[(string) $id][] = $reference;
                }
            }
        }

        return $graph;
    }

    /**
     * @param array<string, list<string>> $graph
     *
     * @return list<string>
     */
    private function cycles(array $graph): array
    {
        $state = [];
        $errors = [];

        foreach (array_keys($graph) as $id) {
            $this->visit((string) $id, $graph, $state, [], $errors);
        }

        return $errors;
    }

    /**
     * @param array<string, list<string>> $graph
     * @param array<string, int>          $state  1 = in progress, 2 = done
     * @param list<string>                $path
     * @param list<string>                $errors
     */
    private function visit(string $id, array $graph, array &$state, array $path, array &$errors): void
    {
        if (($state[$id] ?? 0) === 2) {
            return;
        }

        if (($state[$id] ?? 0) === 1) {
            $errors[] = 'Circular dependency: ' . implode(' -> ', [...$path, $id]);

            return;
        }

        $state[$id] = 1;

        foreach ($graph[$id] ?? [] as $dependency) {
            $this->visit($dependency, $graph, $state, [...$path, $id], $errors);
        }

        $state[$id] = 2;
    }

    /**
     * A singleton must never reach a scoped service, directly or through anything in between.
     *
     * @param array<string, Definition>   $definitions
     * @param array<string, list<string>> $graph
     * @param list<string>                $scopedIds
     *
     * @return list<string>
     */
    private function captives(array $definitions, array $graph, array $scopedIds): array
    {
        $errors = [];

        foreach ($definitions as $id => $definition) {
            if ($definition instanceof AliasDefinition || $this->lifetime((string) $id, $definitions, $scopedIds) !== Lifetime::Singleton) {
                continue;
            }

            $path = $this->reachesScoped((string) $id, $graph, $definitions, $scopedIds, [(string) $id]);

            if ($path !== null) {
                $errors[] = \sprintf(
                    'Singleton "%s" depends on scoped service "%s" (path: %s). A singleton outlives a request; make "%s" scoped or transient, or inject a factory instead.',
                    $id,
                    (string) end($path),
                    implode(' -> ', $path),
                    $id,
                );
            }
        }

        return $errors;
    }

    /**
     * @param array<string, list<string>> $graph
     * @param array<string, Definition>   $definitions
     * @param list<string>                $scopedIds
     * @param list<string>                $path
     *
     * @return list<string>|null
     */
    private function reachesScoped(string $id, array $graph, array $definitions, array $scopedIds, array $path): ?array
    {
        foreach ($graph[$id] ?? [] as $dependency) {
            if (\in_array($dependency, $path, true)) {
                continue;
            }

            if ($this->lifetime($dependency, $definitions, $scopedIds) === Lifetime::Scoped && !($definitions[$dependency] ?? null) instanceof AliasDefinition) {
                return [...$path, $dependency];
            }

            $found = $this->reachesScoped($dependency, $graph, $definitions, $scopedIds, [...$path, $dependency]);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, Definition> $definitions
     * @param list<string>              $scopedIds
     * @param list<string>              $seen
     */
    private function lifetime(string $id, array $definitions, array $scopedIds, array $seen = []): Lifetime
    {
        if (\in_array($id, $scopedIds, true)) {
            return Lifetime::Scoped;
        }

        $definition = $definitions[$id] ?? null;

        if ($definition instanceof AliasDefinition && !\in_array($id, $seen, true)) {
            return $this->lifetime($definition->target, $definitions, $scopedIds, [...$seen, $id]);
        }

        return $definition instanceof ServiceDefinition || $definition instanceof FactoryDefinition ? $definition->lifetime : Lifetime::Singleton;
    }

    /**
     * @param array<string, Definition>   $definitions
     * @param array<string, list<string>> $tags
     * @param list<string>                $modules
     */
    private function render(array $definitions, array $tags, array $modules, string $className): string
    {
        $lifetimes = [];
        $creations = [];

        foreach ($definitions as $id => $definition) {
            $key = var_export((string) $id, true);
            $lifetime = $definition instanceof AliasDefinition ? Lifetime::Transient : ($definition instanceof ServiceDefinition || $definition instanceof FactoryDefinition ? $definition->lifetime : Lifetime::Singleton);
            $lifetimes[] = $key . ' => \\' . Lifetime::class . '::' . $lifetime->name . ',';
            $creations[] = $key . ' => ' . $this->expression($definition, $tags) . ',';
        }

        $lifetimeBody = $lifetimes === []
            ? 'return null;'
            : "return match (\$id) {\n            " . implode("\n            ", $lifetimes) . "\n            default => null,\n        };";
        $createBody = $creations === []
            ? 'throw new \\Trunk\\Container\\Exception\\NotFoundException(\'No entry was found for "\' . $id . \'".\');'
            : "return match (\$id) {\n            " . implode("\n            ", $creations) . "\n            default => throw new \\Trunk\\Container\\Exception\\NotFoundException('No entry was found for \"' . \$id . '\".'),\n        };";

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . 'namespace ' . self::NAMESPACE . ";\n\n"
            . "/**\n * Generated by the Trunk compiler. Do not edit.\n */\n"
            . 'final class ' . $className . " extends \\Trunk\\Container\\CompiledContainer\n{\n"
            . '    public const array MODULES = ' . var_export($modules, true) . ";\n\n"
            . "    protected function lifetimeOf(string \$id): ?\\Trunk\\Container\\Lifetime\n    {\n        " . $lifetimeBody . "\n    }\n\n"
            . "    protected function create(string \$id): mixed\n    {\n        " . $createBody . "\n    }\n}\n";
    }

    /**
     * @param array<string, list<string>> $tags
     */
    private function expression(Definition $definition, array $tags): string
    {
        return match (true) {
            $definition instanceof AliasDefinition => '$this->get(' . var_export($definition->target, true) . ')',
            $definition instanceof ServiceDefinition => 'new \\' . $definition->class . '(' . $this->arguments($definition->arguments, $tags) . ')',
            $definition instanceof FactoryDefinition => '$this->get(' . var_export($definition->factory, true) . ')->' . $definition->method . '(' . $this->arguments($definition->arguments, $tags) . ')',
            default => throw new ContainerException('Unknown definition ' . $definition::class),
        };
    }

    /**
     * @param list<Argument>              $arguments
     * @param array<string, list<string>> $tags
     */
    private function arguments(array $arguments, array $tags): string
    {
        return implode(', ', array_map(fn(Argument $a): string => $this->argument($a, $tags), $arguments));
    }

    /**
     * @param array<string, list<string>> $tags
     */
    private function argument(Argument $argument, array $tags): string
    {
        return match (true) {
            $argument instanceof Reference => '$this->get(' . var_export($argument->id, true) . ')',
            $argument instanceof Value => var_export($argument->value, true),
            $argument instanceof TaggedReference => '[' . implode(', ', array_map(static fn(string $id): string => '$this->get(' . var_export($id, true) . ')', $tags[$argument->tag] ?? [])) . ']',
            $argument instanceof ConfigValue => '$this->get(\\' . Configuration::class . '::class)->' . ($argument->type ?? 'value') . '(' . var_export($argument->key, true) . ')',
            default => throw new ContainerException('Unknown argument ' . $argument::class),
        };
    }
}

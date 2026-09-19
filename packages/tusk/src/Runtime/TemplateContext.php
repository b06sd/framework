<?php

declare(strict_types=1);

namespace Trunk\Tusk\Runtime;

use ArrayAccess;
use Closure;
use Trunk\Tusk\Exception\TemplateRuntimeException;
use Trunk\Tusk\Loader\TemplateLoader;

/**
 * The helper object compiled templates call as `$t`. It owns escaping, variable/property access,
 * slot resolution across the layout chain and includes. Compiled code never touches PHP directly.
 */
final class TemplateContext
{
    private const int MAX_DEPTH = 16;

    /** @var list<CompiledTemplate> most derived template first, outermost layout last */
    private array $chain = [];

    /** Index in the chain of the template whose code is currently running. */
    private int $level = 0;

    public function __construct(
        private readonly TemplateLoader $loader,
        private readonly Filters $filters = new Filters(),
        private readonly int $depth = 0,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function execute(string $name, array $data): void
    {
        if ($this->depth > self::MAX_DEPTH) {
            throw new TemplateRuntimeException('Templates are nested too deeply (circular include?).');
        }

        $this->chain = $this->loadChain($name);
        $this->level = \count($this->chain) - 1;
        ($this->chain[$this->level]->main)($this, $data);
    }

    /**
     * @param array<string, mixed> $v
     */
    public function v(array $v, string $name): mixed
    {
        return \array_key_exists($name, $v) ? $v[$name] : throw new TemplateRuntimeException(\sprintf('Undefined variable "%s".', $name));
    }

    /**
     * @param array<string, mixed> $v
     */
    public function vo(array $v, string $name): mixed
    {
        return $v[$name] ?? null;
    }

    public function attr(mixed $base, mixed $key): mixed
    {
        [$found, $value] = $this->fetch($base, $key);

        return $found ? $value : throw new TemplateRuntimeException(\sprintf('Cannot read "%s" from %s.', \is_scalar($key) ? (string) $key : get_debug_type($key), get_debug_type($base)));
    }

    public function attrOpt(mixed $base, mixed $key): mixed
    {
        return $this->fetch($base, $key)[1];
    }

    /**
     * @return iterable<mixed>
     */
    public function iter(mixed $value): iterable
    {
        return is_iterable($value) ? $value : throw new TemplateRuntimeException(\sprintf('Cannot loop over %s.', get_debug_type($value)));
    }

    public function str(mixed $value): string
    {
        return $this->filters->text($value);
    }

    /**
     * HTML-escaped output (the default for `{{ }}`).
     */
    public function e(mixed $value): string
    {
        return $value instanceof SafeHtml ? $value->html : htmlspecialchars($this->filters->text($value), \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    /**
     * Unescaped output (`<raw value="..." />`).
     */
    public function raw(mixed $value): string
    {
        return $value instanceof SafeHtml ? $value->html : $this->filters->text($value);
    }

    public function in(mixed $needle, mixed $haystack): bool
    {
        return match (true) {
            \is_array($haystack) => \in_array($needle, $haystack, true),
            \is_string($haystack) && \is_string($needle) => $needle === '' || str_contains($haystack, $needle),
            default => throw new TemplateRuntimeException(\sprintf('"in" needs an array or string on the right, %s given.', get_debug_type($haystack))),
        };
    }

    /**
     * @param list<mixed> $arguments
     */
    public function filter(string $name, mixed $value, array $arguments): mixed
    {
        return $this->filters->apply($name, $value, $arguments);
    }

    /**
     * Renders the content a more-derived template supplied for `$name`, or the fallback.
     *
     * @param array<string, mixed>                                      $v
     * @param (Closure(TemplateContext, array<string, mixed>): void)|null $fallback
     */
    public function slot(string $name, array $v, ?Closure $fallback): void
    {
        $caller = $this->level;

        for ($i = $caller - 1; $i >= 0; --$i) {
            if (isset($this->chain[$i]->slots[$name])) {
                $this->level = $i;
                $this->chain[$i]->slots[$name]($this, $v);
                $this->level = $caller;

                return;
            }
        }

        if ($fallback !== null) {
            $fallback($this, $v);
        }
    }

    /**
     * @param array<string, mixed> $v
     */
    public function include(string $name, array $v): void
    {
        new self($this->loader, $this->filters, $this->depth + 1)->execute($name, $v);
    }

    /**
     * @return list<CompiledTemplate>
     */
    private function loadChain(string $name): array
    {
        $chain = [];
        $current = $name;

        while (true) {
            $template = $this->loader->load($current);
            $chain[] = $template;

            if ($template->parent === null) {
                return $chain;
            }

            if (\count($chain) > self::MAX_DEPTH) {
                throw new TemplateRuntimeException('Layouts are nested too deeply (circular layout?).');
            }

            $current = $template->parent;
        }
    }

    /**
     * Reads an array key, ArrayAccess offset or public property. Never calls methods.
     *
     * @return array{bool, mixed}
     */
    private function fetch(mixed $base, mixed $key): array
    {
        if (!\is_string($key) && !\is_int($key)) {
            return [false, null];
        }

        if (\is_array($base)) {
            return \array_key_exists($key, $base) ? [true, $base[$key]] : [false, null];
        }

        if ($base instanceof ArrayAccess) {
            return $base->offsetExists($key) ? [true, $base->offsetGet($key)] : [false, null];
        }

        if (\is_object($base) && \is_string($key)) {
            $properties = get_object_vars($base);

            return \array_key_exists($key, $properties) ? [true, $properties[$key]] : [false, null];
        }

        return [false, null];
    }
}

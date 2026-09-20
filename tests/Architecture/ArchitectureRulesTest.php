<?php

declare(strict_types=1);

namespace Trunk\Tests\Architecture;

use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The architecture rules from docs/ARCHITECTURE_REVIEW.md, enforced. They read source files only
 * (no framework boot), so they are fast and cannot be satisfied by accident of runtime behaviour.
 */
final class ArchitectureRulesTest extends TestCase
{
    /** namespace prefix per package directory name */
    private const array PACKAGES = [
        'router' => 'Trunk\\Router', 'http' => 'Trunk\\Http', 'tusk' => 'Trunk\\Tusk', 'mvc' => 'Trunk\\Mvc', 'console' => 'Trunk\\Console',
        'cache' => 'Trunk\\Cache', 'database' => 'Trunk\\Database', 'orm' => 'Trunk\\Orm', 'queue' => 'Trunk\\Queue', 'observability' => 'Trunk\\Telemetry', 'auth' => 'Trunk\\Auth', 'validation' => 'Trunk\\Validation',
    ];

    public function test_core_imports_nothing_from_optional_packages(): void
    {
        // Arrange
        $violations = [];

        // Act
        foreach (self::imports(self::root() . '/src') as $file => $classes) {
            foreach ($classes as $class) {
                foreach (self::PACKAGES as $prefix) {
                    if (str_starts_with($class, $prefix . '\\')) {
                        $violations[] = str_replace(self::root() . '/', '', $file) . ' imports ' . $class;
                    }
                }
            }
        }

        // Assert
        self::assertSame([], $violations);
    }

    public function test_the_package_graph_has_no_cycles(): void
    {
        // Arrange
        $edges = self::packageEdges();
        $cycles = [];

        // Act
        foreach (array_keys($edges) as $start) {
            self::walk($edges, $start, $start, [$start], $cycles);
        }

        // Assert
        self::assertSame([], array_values(array_unique($cycles)));
    }

    public function test_every_package_declares_exactly_the_trunk_packages_it_imports(): void
    {
        // Arrange
        $problems = [];

        // Act
        foreach (self::packageEdges() as $name => $imported) {
            $composer = self::composer(self::root() . '/packages/' . $name . '/composer.json');
            $required = array_map(static fn(string $r): string => substr($r, \strlen('trunkphp/')), array_filter(array_keys(\is_array($composer['require'] ?? null) ? $composer['require'] : []), static fn(string $r): bool => str_starts_with($r, 'trunkphp/')));

            foreach (array_keys($imported) as $dependency) {
                if (!\in_array($dependency, $required, true)) {
                    $problems[] = $name . ' imports ' . $dependency . ' but does not require trunkphp/' . $dependency;
                }
            }

            foreach ($required as $dependency) {
                if (!isset($imported[$dependency])) {
                    $problems[] = $name . ' requires trunkphp/' . $dependency . ' but never imports it';
                }
            }
        }

        // Assert
        self::assertSame([], $problems);
    }

    public function test_the_core_distribution_requires_only_what_core_uses(): void
    {
        // Arrange
        $composer = self::composer(self::root() . '/composer.json');
        $required = array_keys(\is_array($composer['require'] ?? null) ? $composer['require'] : []);

        // Act
        $extra = array_values(array_diff($required, ['php', 'psr/container', 'psr/log']));

        // Assert
        self::assertSame([], $extra, 'core must not require HTTP, cache or database libraries; those belong to their packages');
    }

    public function test_source_has_no_dynamic_execution_static_state_or_scattered_superglobals(): void
    {
        // Arrange
        $forbiddenCalls = ['eval', 'unserialize', 'shell_exec', 'passthru', 'system', 'popen', 'proc_open', 'exec'];
        $hits = [];

        // Act
        foreach ([self::root() . '/src', ...array_map(static fn(string $p): string => self::root() . '/packages/' . $p . '/src', array_keys(self::PACKAGES))] as $directory) {
            foreach (self::files($directory) as $file) {
                $relative = str_replace(self::root() . '/', '', $file);
                $tokens = PhpToken::tokenize((string) file_get_contents($file));
                $previous = null;

                foreach ($tokens as $i => $token) {
                    if ($token->is(T_WHITESPACE) || $token->is([T_COMMENT, T_DOC_COMMENT])) {
                        continue;
                    }

                    $isCall = $token->is(T_STRING) && ($tokens[$i + 1]->text ?? '') === '(' && !($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW]) ?? false);

                    if (($isCall || $token->is(T_EVAL)) && \in_array(strtolower($token->text), $forbiddenCalls, true) && !str_ends_with($relative, 'ProcOpenLauncher.php')) {
                        $hits[] = $relative . ': ' . $token->text . '()';
                    }

                    if ($token->is(T_VARIABLE) && \in_array($token->text, ['$_SERVER', '$_GET', '$_POST', '$_COOKIE', '$_FILES'], true) && !str_ends_with($relative, 'ServerRequestCreator.php') && !str_ends_with($relative, 'ConsoleKernel.php')) {
                        $hits[] = $relative . ': ' . $token->text;
                    }

                    if ($token->is(T_STATIC) && ($tokens[$i + 2]->text ?? '') !== '' && ($tokens[$i + 2]->is(T_VARIABLE))) {
                        $hits[] = $relative . ': static variable';
                    }

                    $previous = $token;
                }
            }
        }

        // Assert
        self::assertSame([], $hits);
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * @return list<string> absolute paths of PHP files under a directory
     */
    private static function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, list<string>> imported `Trunk\...` class per file
     */
    private static function imports(string $directory): array
    {
        $imports = [];

        foreach (self::files($directory) as $file) {
            preg_match_all('/^use (Trunk\\\\[A-Za-z0-9_\\\\]+)(?: as \w+)?;/m', (string) file_get_contents($file), $matches);
            $imports[$file] = $matches[1];
        }

        return $imports;
    }

    /**
     * @return array<string, array<string, true>> package => imported package names (core excluded)
     */
    private static function packageEdges(): array
    {
        $edges = [];

        foreach (self::PACKAGES as $name => $namespace) {
            if (!is_dir(self::root() . '/packages/' . $name)) {
                continue;
            }

            $edges[$name] = [];

            foreach (self::imports(self::root() . '/packages/' . $name . '/src') as $classes) {
                foreach ($classes as $class) {
                    foreach (self::PACKAGES as $other => $prefix) {
                        if ($other !== $name && str_starts_with($class, $prefix . '\\')) {
                            $edges[$name][$other] = true;
                        }
                    }
                }
            }
        }

        return $edges;
    }

    /**
     * @return array<string, mixed>
     */
    private static function composer(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

        return \is_array($data) ? array_filter($data, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @param array<string, array<string, true>> $edges
     * @param list<string>                       $path
     * @param list<string>                       $cycles
     */
    private static function walk(array $edges, string $node, string $start, array $path, array &$cycles): void
    {
        foreach (array_keys($edges[$node] ?? []) as $next) {
            if ($next === $start) {
                $cycles[] = implode(' -> ', [...$path, $start]);
            } elseif (!\in_array($next, $path, true)) {
                self::walk($edges, $next, $start, [...$path, $next], $cycles);
            }
        }
    }
}

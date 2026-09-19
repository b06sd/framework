<?php

declare(strict_types=1);

namespace Trunk\Foundation\Project;

use ParseError;
use PhpToken;
use Trunk\Foundation\Exception\ProjectException;
use Trunk\Support\ClassName;
use Trunk\Support\FileWriter;

/**
 * Edits the `'modules' => [...]` list in trunk.php without ever executing the file. It works on
 * tokens: only a plain list of class names is understood, and anything else (comments, imports,
 * computed entries) makes it refuse with a clear reason instead of guessing. The result is
 * re-parsed and re-read before it replaces the file.
 */
final readonly class ProjectManifestEditor
{
    public function __construct(private FileWriter $files = new FileWriter()) {}

    /**
     * @return list<string>
     *
     * @throws ProjectException when trunk.php is not in the plain shape this editor understands
     */
    public function read(string $basePath): array
    {
        return $this->locate($this->source($basePath))[2];
    }

    /**
     * @param list<string> $modules
     *
     * @throws ProjectException
     */
    public function write(string $basePath, array $modules): void
    {
        foreach ($modules as $module) {
            if (!ClassName::isValid($module)) {
                throw new ProjectException(\sprintf('"%s" is not a valid class name.', $module));
            }
        }

        $source = $this->source($basePath);
        [$start, $end] = $this->locate($source);
        $lines = implode('', array_map(static fn(string $m): string => '        \\' . ltrim($m, '\\') . "::class,\n", array_values(array_unique($modules))));
        $updated = substr($source, 0, $start) . "[\n" . $lines . '    ]' . substr($source, $end);

        // Prove the new file is valid PHP that says exactly what we intended before it replaces the old one.
        try {
            PhpToken::tokenize($updated, \TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new ProjectException('The edited trunk.php would not be valid PHP (' . $e->getMessage() . '); it was left unchanged.');
        }

        if ($this->locate($updated)[2] !== array_map(static fn(string $m): string => ltrim($m, '\\'), array_values(array_unique($modules)))) {
            throw new ProjectException('The edited trunk.php did not read back as intended; it was left unchanged.');
        }

        $this->files->write($basePath . '/trunk.php', $updated);
    }

    private function source(string $basePath): string
    {
        $source = @file_get_contents($basePath . '/trunk.php');

        return $source === false ? throw new ProjectException('trunk.php could not be read.') : $source;
    }

    /**
     * @return array{int, int, list<string>} byte offset of `[`, offset just after `]`, and the module names
     */
    private function locate(string $source): array
    {
        try {
            $tokens = array_values(array_filter(PhpToken::tokenize($source, \TOKEN_PARSE), static fn(PhpToken $t): bool => !$t->is(\T_WHITESPACE)));
        } catch (ParseError $e) {
            throw new ProjectException('trunk.php is not valid PHP: ' . $e->getMessage());
        }

        foreach ($tokens as $token) {
            if ($token->is([\T_NAMESPACE, \T_USE])) {
                throw new ProjectException('trunk.php uses "namespace" or "use", which the editor does not understand');
            }
        }

        foreach ($tokens as $i => $token) {
            if ($token->is(\T_CONSTANT_ENCAPSED_STRING) && trim($token->text, '\'"') === 'modules' && ($tokens[$i + 1] ?? null)?->is(\T_DOUBLE_ARROW) && ($tokens[$i + 2] ?? null)?->text === '[') {
                return $this->readList($tokens, $i + 2);
            }
        }

        throw new ProjectException('trunk.php has no plain \'modules\' => [ ... ] list');
    }

    /**
     * @param list<PhpToken> $tokens
     *
     * @return array{int, int, list<string>}
     */
    private function readList(array $tokens, int $open): array
    {
        $modules = [];
        $i = $open + 1;

        while (isset($tokens[$i]) && $tokens[$i]->text !== ']') {
            $token = $tokens[$i];

            if ($token->text === ',') {
                ++$i;

                continue;
            }

            if ($token->is([\T_NAME_FULLY_QUALIFIED, \T_NAME_QUALIFIED, \T_STRING]) && ($tokens[$i + 1] ?? null)?->is(\T_DOUBLE_COLON) && ($tokens[$i + 2] ?? null)?->text === 'class') {
                $modules[] = ltrim($token->text, '\\');
                $i += 3;

                continue;
            }

            if ($token->is(\T_CONSTANT_ENCAPSED_STRING)) {
                $modules[] = ltrim(stripslashes(substr($token->text, 1, -1)), '\\');
                ++$i;

                continue;
            }

            throw new ProjectException(\sprintf('the modules list contains "%s", which is not a plain class name (comments and computed entries are not supported)', $token->text));
        }

        if (!isset($tokens[$i])) {
            throw new ProjectException('the modules list is not closed');
        }

        return [$tokens[$open]->pos, $tokens[$i]->pos + 1, $modules];
    }
}

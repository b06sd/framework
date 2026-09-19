<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use PhpToken;

final class ForbiddenConstructScanner
{
    private const array FORBIDDEN_CALLS = [
        'eval', 'unserialize', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'assert',
    ];

    /**
     * @param list<string> $allowedCalls forbidden function names this particular file is explicitly allowed to use
     *
     * @return list<string>
     */
    public function scan(string $code, string $label, array $allowedCalls = []): array
    {
        $violations = [];
        $tokens = PhpToken::tokenize($code);

        foreach ($tokens as $i => $token) {
            // Only calls of the global functions count: `$pdo->exec()` or `Foo::assert()` are methods, not shell access.
            $previous = null;

            for ($j = $i - 1; $j >= 0; --$j) {
                if (!$tokens[$j]->is(\T_WHITESPACE)) {
                    $previous = $tokens[$j];

                    break;
                }
            }

            $isMethodOrDeclaration = $previous !== null && \in_array($previous->text, ['->', '?->', '::', 'function'], true);
            $isForbiddenCall = !$isMethodOrDeclaration
                && $token->is(\T_STRING)
                && ($tokens[$i + 1] ?? null)?->text === '('
                && \in_array(strtolower($token->text), self::FORBIDDEN_CALLS, true)
                && !\in_array(strtolower($token->text), $allowedCalls, true);
            $isForbiddenSyntax = $token->is(\T_EVAL)
                || ($token->is(\T_VARIABLE) && $token->text === '$GLOBALS')
                || $token->text === '`';

            if ($isForbiddenCall || $isForbiddenSyntax) {
                $violations[] = $label . ': ' . $token->text;
            }
        }

        return $violations;
    }
}

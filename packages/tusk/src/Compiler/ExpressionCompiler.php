<?php

declare(strict_types=1);

namespace Trunk\Tusk\Compiler;

use LogicException;
use Trunk\Tusk\Syntax\Expression\Access;
use Trunk\Tusk\Syntax\Expression\Binary;
use Trunk\Tusk\Syntax\Expression\Expression;
use Trunk\Tusk\Syntax\Expression\FilterCall;
use Trunk\Tusk\Syntax\Expression\Literal;
use Trunk\Tusk\Syntax\Expression\Ternary;
use Trunk\Tusk\Syntax\Expression\Unary;
use Trunk\Tusk\Syntax\Expression\Variable;

/**
 * Expression AST -> PHP. Output only ever consists of `$t->helper(...)` calls, operators, and
 * `var_export`ed literals, so template content can never inject PHP code.
 */
final class ExpressionCompiler
{
    private const array OPERATORS = [
        '+' => '+', '-' => '-', '*' => '*', '/' => '/', '%' => '%',
        '<' => '<', '>' => '>', '<=' => '<=', '>=' => '>=',
        '==' => '===', '!=' => '!==',
        'and' => '&&', 'or' => '||',
    ];

    /**
     * @param bool $lenient missing variables and keys yield null instead of an error (used by `default`)
     */
    public function compile(Expression $expression, bool $lenient = false): string
    {
        return match (true) {
            $expression instanceof Literal => $this->literal($expression->value),
            $expression instanceof Variable => \sprintf('$t->%s($v, %s)', $lenient ? 'vo' : 'v', var_export($expression->name, true)),
            $expression instanceof Access => \sprintf('$t->%s(%s, %s)', $lenient ? 'attrOpt' : 'attr', $this->compile($expression->base, $lenient), $this->compile($expression->key)),
            $expression instanceof Unary => $this->unary($expression, $lenient),
            $expression instanceof Binary => $this->binary($expression, $lenient),
            $expression instanceof Ternary => \sprintf('(%s ? %s : %s)', $this->compile($expression->condition, $lenient), $this->compile($expression->then, $lenient), $this->compile($expression->else, $lenient)),
            $expression instanceof FilterCall => $this->filter($expression, $lenient),
            default => throw new LogicException('Unknown expression node ' . $expression::class),
        };
    }

    private function literal(string|int|float|bool|null $value): string
    {
        $code = var_export($value, true);

        return \is_int($value) && $value < 0 || \is_float($value) && $value < 0 ? '(' . $code . ')' : $code;
    }

    private function unary(Unary $unary, bool $lenient): string
    {
        return '(' . ($unary->operator === 'not' ? '!' : $unary->operator) . $this->compile($unary->operand, $lenient) . ')';
    }

    private function binary(Binary $binary, bool $lenient): string
    {
        $left = $this->compile($binary->left, $lenient);
        $right = $this->compile($binary->right, $lenient);

        return match ($binary->operator) {
            '~' => \sprintf('($t->str(%s) . $t->str(%s))', $left, $right),
            'in' => \sprintf('$t->in(%s, %s)', $left, $right),
            default => \sprintf('(%s %s %s)', $left, self::OPERATORS[$binary->operator] ?? throw new LogicException('Unknown operator ' . $binary->operator), $right),
        };
    }

    private function filter(FilterCall $call, bool $lenient): string
    {
        $subject = $this->compile($call->subject, $lenient || $call->filter === 'default');
        $arguments = array_map(fn(Expression $a): string => $this->compile($a, $lenient), $call->arguments);

        return \sprintf('$t->filter(%s, %s, [%s])', var_export($call->filter, true), $subject, implode(', ', $arguments));
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax;

use Trunk\Tusk\Exception\TemplateSyntaxException;
use Trunk\Tusk\Runtime\Filters;
use Trunk\Tusk\Syntax\Expression\Access;
use Trunk\Tusk\Syntax\Expression\Binary;
use Trunk\Tusk\Syntax\Expression\Expression;
use Trunk\Tusk\Syntax\Expression\FilterCall;
use Trunk\Tusk\Syntax\Expression\Literal;
use Trunk\Tusk\Syntax\Expression\Ternary;
use Trunk\Tusk\Syntax\Expression\Unary;
use Trunk\Tusk\Syntax\Expression\Variable;

/**
 * Parses Tusk's expression language with a small recursive-descent grammar:
 *
 *   ternary > or > and > not > comparison(== != < > <= >= in) > additive(+ - ~) > multiplicative(* / %)
 *   > unary(- +) > postfix(.name  [expr]  |filter(args)) > primary
 *
 * There is no function or method call syntax; the only callables are the whitelisted filters.
 */
final class ExpressionParser
{
    private const array KEYWORDS = ['and', 'or', 'not', 'in', 'true', 'false', 'null'];

    /** @var list<array{string, string}> type (num|str|id|op|end) and value */
    private array $tokens = [];

    private int $pos = 0;

    private string $template = '';

    private int $line = 0;

    public function parse(string $source, string $template, int $line): Expression
    {
        $this->template = $template;
        $this->line = $line;
        $this->tokens = $this->tokenize($source);
        $this->pos = 0;

        $expression = $this->ternary();

        if ($this->peek()[0] !== 'end') {
            throw $this->error(\sprintf('Unexpected "%s" in expression "%s".', $this->peek()[1], trim($source)));
        }

        return $expression;
    }

    /**
     * @return list<array{string, string}>
     */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $length = \strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];

            if (ctype_space($char)) {
                ++$i;

                continue;
            }

            if (preg_match('/\G\d+(?:\.\d+)?/', $source, $m, 0, $i) === 1) {
                $tokens[] = ['num', $m[0]];
                $i += \strlen($m[0]);
            } elseif ($char === '"' || $char === "'") {
                [$value, $i] = $this->readString($source, $i);
                $tokens[] = ['str', $value];
            } elseif (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $source, $m, 0, $i) === 1) {
                $tokens[] = ['id', $m[0]];
                $i += \strlen($m[0]);
            } elseif (preg_match('/\G(?:==|!=|<=|>=|[<>+\-*\/%~?:.,()\[\]|])/', $source, $m, 0, $i) === 1) {
                $tokens[] = ['op', $m[0]];
                $i += \strlen($m[0]);
            } else {
                throw $this->error(\sprintf('Unexpected character "%s" in expression "%s".', $char, trim($source)));
            }
        }

        $tokens[] = ['end', 'end of expression'];

        return $tokens;
    }

    /**
     * @return array{string, int} the decoded string and the offset after the closing quote
     */
    private function readString(string $source, int $start): array
    {
        $quote = $source[$start];
        $length = \strlen($source);
        $value = '';

        for ($i = $start + 1; $i < $length; ++$i) {
            $char = $source[$i];

            if ($char === '\\' && $i + 1 < $length) {
                ++$i;
                $value .= match ($source[$i]) {
                    'n' => "\n",
                    't' => "\t",
                    default => $source[$i],
                };

                continue;
            }

            if ($char === $quote) {
                return [$value, $i + 1];
            }

            $value .= $char;
        }

        throw $this->error('Unterminated string in expression.');
    }

    private function ternary(): Expression
    {
        $condition = $this->or();

        if (!$this->isOp('?')) {
            return $condition;
        }

        ++$this->pos;
        $then = $this->ternary();
        $this->expectOp(':');

        return new Ternary($condition, $then, $this->ternary());
    }

    private function or(): Expression
    {
        $left = $this->and();

        while ($this->isWord('or')) {
            ++$this->pos;
            $left = new Binary('or', $left, $this->and());
        }

        return $left;
    }

    private function and(): Expression
    {
        $left = $this->not();

        while ($this->isWord('and')) {
            ++$this->pos;
            $left = new Binary('and', $left, $this->not());
        }

        return $left;
    }

    private function not(): Expression
    {
        if ($this->isWord('not')) {
            ++$this->pos;

            return new Unary('not', $this->not());
        }

        return $this->comparison();
    }

    private function comparison(): Expression
    {
        $left = $this->additive();

        foreach (['==', '!=', '<=', '>=', '<', '>'] as $operator) {
            if ($this->isOp($operator)) {
                ++$this->pos;

                return new Binary($operator, $left, $this->additive());
            }
        }

        if ($this->isWord('in')) {
            ++$this->pos;

            return new Binary('in', $left, $this->additive());
        }

        return $left;
    }

    private function additive(): Expression
    {
        $left = $this->multiplicative();

        while ($this->isOp('+') || $this->isOp('-') || $this->isOp('~')) {
            $operator = $this->tokens[$this->pos++][1];
            $left = new Binary($operator, $left, $this->multiplicative());
        }

        return $left;
    }

    private function multiplicative(): Expression
    {
        $left = $this->unary();

        while ($this->isOp('*') || $this->isOp('/') || $this->isOp('%')) {
            $operator = $this->tokens[$this->pos++][1];
            $left = new Binary($operator, $left, $this->unary());
        }

        return $left;
    }

    private function unary(): Expression
    {
        if ($this->isOp('-') || $this->isOp('+')) {
            $operator = $this->tokens[$this->pos++][1];

            return new Unary($operator === '-' ? '-' : '+', $this->unary());
        }

        return $this->postfix();
    }

    private function postfix(): Expression
    {
        $expression = $this->primary();

        while (true) {
            if ($this->isOp('.')) {
                ++$this->pos;
                [$type, $value] = $this->tokens[$this->pos++];

                if ($type === 'id') {
                    $expression = new Access($expression, new Literal($value));
                } elseif ($type === 'num' && ctype_digit($value)) {
                    $expression = new Access($expression, new Literal((int) $value));
                } else {
                    throw $this->error('Expected a property name after ".".');
                }
            } elseif ($this->isOp('[')) {
                ++$this->pos;
                $key = $this->ternary();
                $this->expectOp(']');
                $expression = new Access($expression, $key);
            } elseif ($this->isOp('|')) {
                ++$this->pos;
                $expression = $this->filter($expression);
            } else {
                return $expression;
            }
        }
    }

    private function filter(Expression $subject): Expression
    {
        [$type, $name] = $this->tokens[$this->pos++];

        if ($type !== 'id' || !isset(Filters::ARITY[$name])) {
            throw $this->error(\sprintf('Unknown filter "%s". Available: %s.', $name, implode(', ', array_keys(Filters::ARITY))));
        }

        $arguments = [];

        if ($this->isOp('(')) {
            ++$this->pos;

            while (!$this->isOp(')')) {
                $arguments[] = $this->ternary();

                if ($this->isOp(',')) {
                    ++$this->pos;
                } elseif (!$this->isOp(')')) {
                    throw $this->error('Expected "," or ")" in filter arguments.');
                }
            }

            ++$this->pos;
        }

        [$min, $max] = Filters::ARITY[$name];

        if (\count($arguments) < $min || \count($arguments) > $max) {
            throw $this->error(\sprintf('Filter "%s" takes %s argument(s), %d given.', $name, $min === $max ? (string) $min : $min . '-' . $max, \count($arguments)));
        }

        return new FilterCall($name, $subject, $arguments);
    }

    private function primary(): Expression
    {
        [$type, $value] = $this->tokens[$this->pos++];

        if ($type === 'num') {
            return new Literal(str_contains($value, '.') ? (float) $value : (int) $value);
        }

        if ($type === 'str') {
            return new Literal($value);
        }

        if ($type === 'id') {
            return $this->identifier($value);
        }

        if ($type === 'op' && $value === '(') {
            $inner = $this->ternary();
            $this->expectOp(')');

            return $inner;
        }

        throw $this->error(\sprintf('Unexpected "%s" in expression.', $value));
    }

    private function identifier(string $name): Expression
    {
        if ($this->isOp('(')) {
            throw $this->error(\sprintf('Function and method calls are not allowed ("%s(...)"); use a filter instead.', $name));
        }

        return match ($name) {
            'true' => new Literal(true),
            'false' => new Literal(false),
            'null' => new Literal(null),
            default => \in_array($name, self::KEYWORDS, true)
                ? throw $this->error(\sprintf('"%s" is a reserved word.', $name))
                : new Variable($name),
        };
    }

    /**
     * @return array{string, string}
     */
    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    private function isOp(string $operator): bool
    {
        return $this->tokens[$this->pos][0] === 'op' && $this->tokens[$this->pos][1] === $operator;
    }

    private function isWord(string $word): bool
    {
        return $this->tokens[$this->pos][0] === 'id' && $this->tokens[$this->pos][1] === $word;
    }

    private function expectOp(string $operator): void
    {
        if (!$this->isOp($operator)) {
            throw $this->error(\sprintf('Expected "%s" but found "%s".', $operator, $this->peek()[1]));
        }

        ++$this->pos;
    }

    private function error(string $message): TemplateSyntaxException
    {
        return new TemplateSyntaxException($this->template, $this->line, $message);
    }
}

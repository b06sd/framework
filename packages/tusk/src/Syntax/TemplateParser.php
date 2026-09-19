<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax;

use Trunk\Tusk\Exception\TemplateSyntaxException;
use Trunk\Tusk\Syntax\Expression\Expression;
use Trunk\Tusk\Syntax\Node\ForNode;
use Trunk\Tusk\Syntax\Node\IfNode;
use Trunk\Tusk\Syntax\Node\IncludeNode;
use Trunk\Tusk\Syntax\Node\LayoutNode;
use Trunk\Tusk\Syntax\Node\Node;
use Trunk\Tusk\Syntax\Node\OutputNode;
use Trunk\Tusk\Syntax\Node\SetNode;
use Trunk\Tusk\Syntax\Node\SlotNode;
use Trunk\Tusk\Syntax\Node\TextNode;
use Trunk\Tusk\TemplateName;

/**
 * Builds the template AST from scanner tokens and validates structure: balanced tags, required
 * attributes, valid names, layout rules.
 */
final class TemplateParser
{
    /** @var list<Token> */
    private array $tokens = [];

    private int $pos = 0;

    private string $template = '';

    public function __construct(
        private readonly TemplateScanner $scanner = new TemplateScanner(),
        private readonly ExpressionParser $expressions = new ExpressionParser(),
    ) {}

    public function parse(string $source, string $template): Template
    {
        $this->template = $template;
        $this->tokens = $this->scanner->scan($source, $template);
        $this->pos = 0;

        $nodes = $this->nodes([]);
        $layouts = array_values(array_filter($nodes, static fn(Node $n): bool => $n instanceof LayoutNode));

        if ($layouts === []) {
            return new Template(null, $nodes);
        }

        foreach ($nodes as $node) {
            if (!$node instanceof LayoutNode && !($node instanceof TextNode && trim($node->text) === '')) {
                throw new TemplateSyntaxException($template, 1, 'A template using <layout> must contain nothing outside it.');
            }
        }

        if (\count($layouts) > 1) {
            throw new TemplateSyntaxException($template, 1, 'A template can use only one <layout>.');
        }

        return new Template($layouts[0], $layouts);
    }

    /**
     * Parses nodes until a closing tag or separator listed in `$stops` ("/if", "else", ...) or the end.
     * `$fills` is non-null only directly inside a <layout>, where <fill> is allowed.
     *
     * @param list<string>                   $stops
     * @param array<string, list<Node>>|null $fills
     *
     * @return list<Node>
     */
    private function nodes(array $stops, ?array &$fills = null): array
    {
        $nodes = [];

        while (isset($this->tokens[$this->pos])) {
            $token = $this->tokens[$this->pos];

            if ($token->kind === TokenKind::Close || ($token->kind === TokenKind::Open && \in_array($token->name, ['else', 'elseif'], true))) {
                $marker = ($token->kind === TokenKind::Close ? '/' : '') . $token->name;

                if (\in_array($marker, $stops, true)) {
                    return $nodes;
                }

                throw $this->error($token, $token->kind === TokenKind::Close ? \sprintf('Unexpected closing tag </%s>.', $token->name) : \sprintf('<%s> is only allowed inside <if> (or <for> for <else>).', $token->name));
            }

            ++$this->pos;

            if ($token->kind === TokenKind::Text) {
                $nodes[] = new TextNode($token->text);
            } elseif ($token->kind === TokenKind::Output) {
                $nodes[] = new OutputNode($this->expression($token->text, $token->line), false, $token->line);
            } elseif ($token->name === 'fill') {
                if ($fills === null) {
                    throw $this->error($token, '<fill> is only allowed directly inside <layout>.');
                }

                $slot = $this->requireAttribute($token, 'slot');

                if (!TemplateName::isVariable($slot) || isset($fills[$slot])) {
                    throw $this->error($token, \sprintf('Invalid or duplicate slot name "%s".', $slot));
                }

                $none = null;
                $fills[$slot] = $this->body($token, $none);
            } else {
                $nodes[] = $this->tag($token);
            }
        }

        if ($stops !== []) {
            throw new TemplateSyntaxException($this->template, $this->tokens === [] ? 1 : $this->tokens[array_key_last($this->tokens)]->line, \sprintf('Missing %s.', $this->describeStop($stops)));
        }

        return $nodes;
    }

    /**
     * Body of an open tag: everything up to its own closing tag.
     *
     * @param array<string, list<Node>>|null $fills
     *
     * @return list<Node>
     */
    private function body(Token $open, ?array &$fills): array
    {
        if ($open->selfClosing) {
            return [];
        }

        $nodes = $this->nodes(['/' . $open->name], $fills);
        ++$this->pos;

        return $nodes;
    }

    private function tag(Token $token): Node
    {
        return match ($token->name) {
            'if' => $this->ifTag($token),
            'for' => $this->forTag($token),
            'layout' => $this->layoutTag($token),
            'slot' => $this->slotTag($token),
            'include' => $this->includeTag($token),
            'set' => $this->setTag($token),
            'raw' => $this->rawTag($token),
            default => throw $this->error($token, \sprintf('<%s> cannot be used here.', $token->name)),
        };
    }

    private function ifTag(Token $token): Node
    {
        $this->assertOpen($token);
        $branches = [];
        $else = null;
        $condition = $this->expression($this->requireAttribute($token, 'test'), $token->line);

        while (true) {
            $body = $this->nodes(['/if', 'elseif', 'else']);
            $branches[] = [$condition, $body];
            $stop = $this->tokens[$this->pos] ?? null;

            if ($stop === null || $stop->kind === TokenKind::Close) {
                ++$this->pos;

                break;
            }

            ++$this->pos;

            if ($stop->name === 'elseif') {
                $condition = $this->expression($this->requireAttribute($stop, 'test'), $stop->line);

                continue;
            }

            $else = $this->nodes(['/if']);
            ++$this->pos;

            break;
        }

        return new IfNode($branches, $else, $token->line);
    }

    private function forTag(Token $token): Node
    {
        $this->assertOpen($token);
        $each = $this->expression($this->requireAttribute($token, 'each'), $token->line);
        $as = $this->variableName($token, 'as');
        $key = isset($token->attributes['key']) ? $this->variableName($token, 'key') : null;
        $body = $this->nodes(['/for', 'else']);
        $empty = null;

        if (($this->tokens[$this->pos]->kind ?? null) === TokenKind::Open) {
            ++$this->pos;
            $empty = $this->nodes(['/for']);
        }

        ++$this->pos;

        return new ForNode($each, $as, $key, $body, $empty, $token->line);
    }

    private function layoutTag(Token $token): Node
    {
        $this->assertOpen($token);
        $name = $this->requireAttribute($token, 'name');
        $template = 'layouts/' . $name;

        if (!TemplateName::isValid($template)) {
            throw $this->error($token, \sprintf('"%s" is not a valid layout name.', $name));
        }

        $fills = [];
        $default = $this->nodes(['/layout'], $fills);
        ++$this->pos;

        return new LayoutNode($template, $default, $fills ?? []);
    }

    private function slotTag(Token $token): Node
    {
        $name = $token->attributes['name'] ?? 'default';

        if (!TemplateName::isVariable($name)) {
            throw $this->error($token, \sprintf('"%s" is not a valid slot name.', $name));
        }

        $none = null;

        return new SlotNode($name, $this->body($token, $none), $token->line);
    }

    private function includeTag(Token $token): Node
    {
        $this->assertSelfClosing($token);
        $name = $this->requireAttribute($token, 'name');

        if (!TemplateName::isValid($name)) {
            throw $this->error($token, \sprintf('"%s" is not a valid template name.', $name));
        }

        $with = [];

        foreach ($token->attributes as $attribute => $value) {
            if ($attribute === 'name') {
                continue;
            }

            if (!TemplateName::isVariable($attribute)) {
                throw $this->error($token, \sprintf('"%s" is not a valid variable name.', $attribute));
            }

            $with[$attribute] = $this->expression($value, $token->line);
        }

        return new IncludeNode($name, $with, $token->line);
    }

    private function setTag(Token $token): Node
    {
        $this->assertSelfClosing($token);

        return new SetNode($this->variableName($token, 'name'), $this->expression($this->requireAttribute($token, 'value'), $token->line), $token->line);
    }

    private function rawTag(Token $token): Node
    {
        $this->assertSelfClosing($token);

        return new OutputNode($this->expression($this->requireAttribute($token, 'value'), $token->line), true, $token->line);
    }

    private function expression(string $source, int $line): Expression
    {
        return $this->expressions->parse($source, $this->template, $line);
    }

    private function requireAttribute(Token $token, string $name): string
    {
        return $token->attributes[$name] ?? throw $this->error($token, \sprintf('<%s> needs a "%s" attribute.', $token->name, $name));
    }

    private function variableName(Token $token, string $attribute): string
    {
        $name = $this->requireAttribute($token, $attribute);

        return TemplateName::isVariable($name) ? $name : throw $this->error($token, \sprintf('"%s" is not a valid variable name.', $name));
    }

    private function assertOpen(Token $token): void
    {
        if ($token->selfClosing) {
            throw $this->error($token, \sprintf('<%s> cannot be self-closing.', $token->name));
        }
    }

    private function assertSelfClosing(Token $token): void
    {
        if (!$token->selfClosing) {
            throw $this->error($token, \sprintf('<%s> must be self-closing: <%s ... />.', $token->name, $token->name));
        }
    }

    /**
     * @param list<string> $stops
     */
    private function describeStop(array $stops): string
    {
        return implode(' or ', array_map(static fn(string $s): string => '<' . $s . '>', $stops));
    }

    private function error(Token $token, string $message): TemplateSyntaxException
    {
        return new TemplateSyntaxException($this->template, $token->line, $message);
    }
}

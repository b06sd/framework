<?php

declare(strict_types=1);

namespace Trunk\Tusk\Compiler;

use LogicException;
use Trunk\Tusk\Syntax\Node\ForNode;
use Trunk\Tusk\Syntax\Node\IfNode;
use Trunk\Tusk\Syntax\Node\IncludeNode;
use Trunk\Tusk\Syntax\Node\Node;
use Trunk\Tusk\Syntax\Node\OutputNode;
use Trunk\Tusk\Syntax\Node\SetNode;
use Trunk\Tusk\Syntax\Node\SlotNode;
use Trunk\Tusk\Syntax\Node\TextNode;
use Trunk\Tusk\Syntax\TemplateParser;

/**
 * Compiles Tusk source to a PHP file that returns a CompiledTemplate. The generated code is built
 * only from validated AST nodes; no template text is ever emitted as code.
 */
final class TemplateCompiler
{
    private const string CLOSURE = 'static function (\Trunk\Tusk\Runtime\TemplateContext $t, array $v): void {';

    private int $counter = 0;

    /** @var list<string> */
    private array $dependencies = [];

    public function __construct(
        private readonly TemplateParser $parser = new TemplateParser(),
        private readonly ExpressionCompiler $expressions = new ExpressionCompiler(),
    ) {}

    /**
     * @throws \Trunk\Tusk\Exception\TemplateSyntaxException
     */
    public function compile(string $source, string $name): CompiledSource
    {
        $this->counter = 0;
        $this->dependencies = [];
        $template = $this->parser->parse($source, $name);
        $layout = $template->layout;

        $slots = [];
        $parent = 'null';
        $main = '';

        if ($layout !== null) {
            $parent = var_export($layout->template, true);
            $this->dependencies[] = $layout->template;

            if (trim($this->plain($layout->default)) !== '' || $this->hasOutput($layout->default)) {
                $slots['default'] = $this->body($layout->default);
            }

            foreach ($layout->fills as $slot => $nodes) {
                $slots[$slot] = $this->body($nodes);
            }
        } else {
            $main = $this->body($template->body);
        }

        $slotCode = '';

        foreach ($slots as $slot => $code) {
            $slotCode .= '        ' . var_export($slot, true) . ' => ' . self::CLOSURE . "\n" . $code . "        },\n";
        }

        $php = "<?php\n\ndeclare(strict_types=1);\n\n// tusk-template: " . (preg_replace('/[^A-Za-z0-9_\/.-]/', '?', $name) ?? '?') . "\n\n"
            . "return new \\Trunk\\Tusk\\Runtime\\CompiledTemplate(\n"
            . '    parent: ' . $parent . ",\n"
            . "    slots: [\n" . $slotCode . "    ],\n"
            . '    main: ' . self::CLOSURE . "\n" . $main . "    },\n"
            . ");\n";

        return new CompiledSource($php, array_values(array_unique($this->dependencies)));
    }

    /**
     * @param list<Node> $nodes
     */
    private function body(array $nodes): string
    {
        $code = '';
        $pending = '';

        foreach ($nodes as $node) {
            if ($node instanceof TextNode) {
                $pending .= $node->text;

                continue;
            }

            $code .= $this->flushText($pending) . $this->node($node);
            $pending = '';
        }

        return $code . $this->flushText($pending);
    }

    private function flushText(string $text): string
    {
        return $text === '' ? '' : '            echo ' . var_export($text, true) . ";\n";
    }

    /**
     * Each statement carries a trailing `// tusk:LINE` comment so a runtime error in the generated PHP
     * can be reported at the template line it came from.
     */
    private function node(Node $node): string
    {
        $code = $this->compileNode($node);
        $line = match (true) {
            $node instanceof OutputNode, $node instanceof SetNode, $node instanceof IfNode, $node instanceof ForNode, $node instanceof SlotNode, $node instanceof IncludeNode => $node->line,
            default => 0,
        };

        return $line > 0 ? (string) preg_replace('/\n/', ' // tusk:' . $line . "\n", $code, 1) : $code;
    }

    private function compileNode(Node $node): string
    {
        return match (true) {
            $node instanceof OutputNode => '            echo $t->' . ($node->raw ? 'raw' : 'e') . '(' . $this->expressions->compile($node->expression) . ");\n",
            $node instanceof SetNode => '            $v[' . var_export($node->name, true) . '] = ' . $this->expressions->compile($node->value) . ";\n",
            $node instanceof IfNode => $this->ifNode($node),
            $node instanceof ForNode => $this->forNode($node),
            $node instanceof SlotNode => $this->slotNode($node),
            $node instanceof IncludeNode => $this->includeNode($node),
            default => throw new LogicException('Unexpected node ' . $node::class),
        };
    }

    private function ifNode(IfNode $node): string
    {
        $code = '';

        foreach ($node->branches as $index => [$condition, $body]) {
            $code .= '            ' . ($index === 0 ? 'if' : '} elseif') . ' (' . $this->expressions->compile($condition) . ") {\n" . $this->body($body);
        }

        if ($node->else !== null) {
            $code .= "            } else {\n" . $this->body($node->else);
        }

        return $code . "            }\n";
    }

    private function forNode(ForNode $node): string
    {
        $n = ++$this->counter;
        $code = '            $__n' . $n . " = 0;\n"
            . '            foreach ($t->iter(' . $this->expressions->compile($node->each) . ') as $__k' . $n . ' => $__i' . $n . ") {\n"
            . '                ++$__n' . $n . ";\n";

        if ($node->key !== null) {
            $code .= '                $v[' . var_export($node->key, true) . '] = $__k' . $n . ";\n";
        }

        $code .= '                $v[' . var_export($node->as, true) . '] = $__i' . $n . ";\n" . $this->body($node->body) . "            }\n";

        if ($node->empty !== null) {
            $code .= '            if ($__n' . $n . " === 0) {\n" . $this->body($node->empty) . "            }\n";
        }

        return $code;
    }

    private function slotNode(SlotNode $node): string
    {
        $fallback = $node->fallback === [] ? 'null' : self::CLOSURE . "\n" . $this->body($node->fallback) . '            }';

        return '            $t->slot(' . var_export($node->name, true) . ', $v, ' . $fallback . ");\n";
    }

    private function includeNode(IncludeNode $node): string
    {
        $this->dependencies[] = $node->name;
        $with = [];

        foreach ($node->with as $name => $expression) {
            $with[] = var_export($name, true) . ' => ' . $this->expressions->compile($expression);
        }

        return '            $t->include(' . var_export($node->name, true) . ', [' . implode(', ', $with) . '] + $v);' . "\n";
    }

    /**
     * @param list<Node> $nodes
     */
    private function plain(array $nodes): string
    {
        return implode('', array_map(static fn(Node $n): string => $n instanceof TextNode ? $n->text : '', $nodes));
    }

    /**
     * @param list<Node> $nodes
     */
    private function hasOutput(array $nodes): bool
    {
        return array_filter($nodes, static fn(Node $n): bool => !$n instanceof TextNode) !== [];
    }
}

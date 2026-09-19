<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax;

use Trunk\Tusk\Exception\TemplateSyntaxException;

/**
 * Splits a template into text, `{{ }}` outputs and Tusk tags. Only the known Tusk tag names are
 * treated as tags; every other `<...>` is ordinary HTML and stays in the text. PHP open tags are
 * rejected outright, because a `.tusk.php` file must never contain PHP.
 */
final class TemplateScanner
{
    public const array TAGS = ['layout', 'slot', 'fill', 'if', 'elseif', 'else', 'for', 'include', 'set', 'raw', 'comment'];

    /**
     * @return list<Token>
     */
    public function scan(string $source, string $template): array
    {
        if (($php = strpos($source, '<?')) !== false) {
            throw new TemplateSyntaxException($template, $this->lineAt($source, $php), 'PHP tags ("<?") are not allowed in Tusk templates.');
        }

        $tokens = [];
        $length = \strlen($source);
        $pos = 0;
        $line = 1;
        $text = '';
        $textLine = 1;

        while ($pos < $length) {
            $next = $this->nextSpecial($source, $pos);

            if ($next === null) {
                $text .= substr($source, $pos);
                $pos = $length;

                break;
            }

            if ($next > $pos) {
                $chunk = substr($source, $pos, $next - $pos);
                $text .= $chunk;
                $line += substr_count($chunk, "\n");
                $pos = $next;
            }

            if ($source[$pos] === '{') {
                $this->flush($tokens, $text, $textLine);
                [$expression, $pos] = $this->readOutput($source, $pos, $template, $line);
                $tokens[] = new Token(TokenKind::Output, $line, $expression);
                $line += substr_count($expression, "\n");
                $textLine = $line;

                continue;
            }

            $tag = $this->matchTag($source, $pos);

            if ($tag === null) {
                $text .= '<';
                ++$pos;

                continue;
            }

            $this->flush($tokens, $text, $textLine);
            [$name, $isClose, $offset] = $tag;

            if ($isClose) {
                $end = strpos($source, '>', $offset);

                if ($end === false) {
                    throw new TemplateSyntaxException($template, $line, \sprintf('Unterminated closing tag </%s>.', $name));
                }

                $tokens[] = new Token(TokenKind::Close, $line, name: $name);
                $consumed = substr($source, $pos, $end + 1 - $pos);
                $pos = $end + 1;
                $line += substr_count($consumed, "\n");
                $textLine = $line;

                continue;
            }

            [$attributes, $selfClosing, $after] = $this->readAttributes($source, $offset, $template, $line, $name);
            $consumed = substr($source, $pos, $after - $pos);
            $tokenLine = $line;
            $line += substr_count($consumed, "\n");
            $pos = $after;

            if ($name === 'comment') {
                if (!$selfClosing) {
                    $close = strpos($source, '</comment>', $pos);

                    if ($close === false) {
                        throw new TemplateSyntaxException($template, $tokenLine, 'The <comment> tag is never closed.');
                    }

                    $line += substr_count(substr($source, $pos, $close - $pos), "\n");
                    $pos = $close + \strlen('</comment>');
                }

                $textLine = $line;

                continue;
            }

            $tokens[] = new Token(TokenKind::Open, $tokenLine, name: $name, attributes: $attributes, selfClosing: $selfClosing);
            $textLine = $line;
        }

        $this->flush($tokens, $text, $textLine);

        return $tokens;
    }

    private function nextSpecial(string $source, int $from): ?int
    {
        $tag = strpos($source, '<', $from);
        $output = strpos($source, '{{', $from);

        if ($tag === false && $output === false) {
            return null;
        }

        return min($tag === false ? PHP_INT_MAX : $tag, $output === false ? PHP_INT_MAX : $output);
    }

    /**
     * @param list<Token> $tokens
     */
    private function flush(array &$tokens, string &$text, int $line): void
    {
        if ($text !== '') {
            $tokens[] = new Token(TokenKind::Text, $line, $text);
            $text = '';
        }
    }

    /**
     * @return array{string, int} the expression and the offset after `}}`
     */
    private function readOutput(string $source, int $start, string $template, int $line): array
    {
        $length = \strlen($source);
        $quote = null;

        for ($i = $start + 2; $i < $length; ++$i) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    ++$i;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '}' && ($source[$i + 1] ?? '') === '}') {
                $expression = trim(substr($source, $start + 2, $i - $start - 2));

                if ($expression === '') {
                    throw new TemplateSyntaxException($template, $line, 'Empty {{ }} output.');
                }

                return [$expression, $i + 2];
            }
        }

        throw new TemplateSyntaxException($template, $line, 'Unterminated {{ output: missing "}}".');
    }

    /**
     * @return array{string, bool, int}|null tag name, whether it is a closing tag, offset after the name
     */
    private function matchTag(string $source, int $pos): ?array
    {
        $isClose = ($source[$pos + 1] ?? '') === '/';
        $start = $pos + ($isClose ? 2 : 1);

        if (preg_match('/\G[a-z]+/', $source, $m, 0, $start) !== 1 || !\in_array($m[0], self::TAGS, true)) {
            return null;
        }

        $after = $source[$start + \strlen($m[0])] ?? '';

        if ($after !== '' && !ctype_space($after) && $after !== '>' && !($after === '/' && !$isClose)) {
            return null;
        }

        return [$m[0], $isClose, $start + \strlen($m[0])];
    }

    /**
     * @return array{array<string, string>, bool, int} attributes, self-closing flag, offset after `>`
     */
    private function readAttributes(string $source, int $pos, string $template, int $line, string $tag): array
    {
        $attributes = [];
        $length = \strlen($source);

        while (true) {
            while ($pos < $length && ctype_space($source[$pos])) {
                if ($source[$pos] === "\n") {
                    ++$line;
                }

                ++$pos;
            }

            if ($pos >= $length) {
                throw new TemplateSyntaxException($template, $line, \sprintf('The <%s> tag is never closed with ">".', $tag));
            }

            if ($source[$pos] === '>') {
                return [$attributes, false, $pos + 1];
            }

            if ($source[$pos] === '/' && ($source[$pos + 1] ?? '') === '>') {
                return [$attributes, true, $pos + 2];
            }

            if (preg_match('/\G[A-Za-z][A-Za-z0-9_-]*/', $source, $m, 0, $pos) !== 1) {
                throw new TemplateSyntaxException($template, $line, \sprintf('Invalid attribute in <%s>.', $tag));
            }

            $name = $m[0];
            $pos += \strlen($name);

            if (isset($attributes[$name])) {
                throw new TemplateSyntaxException($template, $line, \sprintf('Duplicate attribute "%s" in <%s>.', $name, $tag));
            }

            if (($source[$pos] ?? '') !== '=' || !\in_array($source[$pos + 1] ?? '', ['"', "'"], true)) {
                throw new TemplateSyntaxException($template, $line, \sprintf('Attribute "%s" in <%s> needs a quoted value.', $name, $tag));
            }

            $quote = $source[$pos + 1];
            $end = strpos($source, $quote, $pos + 2);

            if ($end === false) {
                throw new TemplateSyntaxException($template, $line, \sprintf('Unterminated value for attribute "%s" in <%s>.', $name, $tag));
            }

            $attributes[$name] = substr($source, $pos + 2, $end - $pos - 2);
            $line += substr_count($attributes[$name], "\n");
            $pos = $end + 1;
        }
    }

    private function lineAt(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, $offset) + 1;
    }
}

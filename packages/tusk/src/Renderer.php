<?php

declare(strict_types=1);

namespace Trunk\Tusk;

use Throwable;
use Trunk\Tusk\Exception\TemplateNotFoundException;
use Trunk\Tusk\Exception\TemplateRuntimeException;
use Trunk\Tusk\Exception\TemplateSyntaxException;
use Trunk\Tusk\Loader\SourceLocator;
use Trunk\Tusk\Loader\TemplateLoader;
use Trunk\Tusk\Runtime\TemplateContext;

/**
 * Renders a template to a string. Output buffering is always unwound, even when rendering fails.
 *
 * @api
 */
final readonly class Renderer
{
    /** @internal wired by the container, not part of the API */
    public function __construct(private TemplateLoader $loader) {}

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        $level = ob_get_level();
        ob_start();

        try {
            new TemplateContext($this->loader)->execute($name, $data);
            $output = ob_get_clean();

            return $output === false ? '' : $output;
        } catch (TemplateSyntaxException|TemplateNotFoundException $e) {
            $this->unwind($level);

            throw $e;
        } catch (Throwable $e) {
            $this->unwind($level);

            throw TemplateRuntimeException::in($name, $e, $this->loader instanceof SourceLocator ? $this->loader : null);
        }
    }

    private function unwind(int $level): void
    {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }
}

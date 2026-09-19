<?php

declare(strict_types=1);

namespace Trunk\Error;

/**
 * An exception whose real origin is a source file other than the PHP file that threw (a template,
 * for example). The development error page shows that file and line instead of the generated code.
 *
 * @api
 */
interface SourceLocated
{
    /** Absolute path of the originating source file, or null when it is not known. */
    public function sourceFile(): ?string;

    public function sourceLine(): ?int;
}

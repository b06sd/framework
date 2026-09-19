<?php

declare(strict_types=1);

namespace Trunk\Tusk\Loader;

use Trunk\Tusk\Exception\TemplateNotFoundException;
use Trunk\Tusk\Runtime\CompiledTemplate;

interface TemplateLoader
{
    /**
     * @throws TemplateNotFoundException
     */
    public function load(string $name): CompiledTemplate;
}

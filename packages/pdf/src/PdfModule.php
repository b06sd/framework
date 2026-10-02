<?php

declare(strict_types=1);

namespace Trunk\Pdf;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\Module;
use Trunk\Foundation\Configuration;

/**
 * HTML to PDF over dompdf: inject `Pdf`. Settings in config/pdf.php.
 *
 * @api
 */
final class PdfModule implements Module
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->service(Pdf::class, Pdf::class, [new Reference(Configuration::class)]);
    }

    public function boot(ContainerInterface $container): void {}
}

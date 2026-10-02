<?php

declare(strict_types=1);

namespace Trunk\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Throwable;
use Trunk\Foundation\Configuration;
use Trunk\Pdf\Exception\PdfException;

/**
 * HTML to PDF, through dompdf (pure PHP: no browser or service to run). Render the HTML however you
 * like, a Tusk template for instance, and send the result with ResponseBuilder::download():
 *
 *   $bytes = $pdf->render($views->render('invoices/show', ['invoice' => $invoice]));
 *   return $responses->download($bytes, 'invoice-7.pdf', 'application/pdf');
 *
 * The HTML may carry what users typed, so dompdf is locked down: it never fetches a URL (no requests
 * to your network on an attacker's behalf), never runs PHP or JavaScript, and reads local files
 * (images, stylesheets) only from `pdf.assets`.
 *
 * @api
 */
final readonly class Pdf
{
    private const array PAPER = ['a3', 'a4', 'a5', 'letter', 'legal'];

    /** @internal wired by PdfModule */
    public function __construct(private Configuration $configuration) {}

    /**
     * @param string|null $paper       a3, a4, a5, letter or legal; null uses `pdf.paper`
     * @param string      $orientation portrait or landscape
     *
     * @return string the PDF document
     *
     * @throws PdfException when the paper or orientation is unknown, or the document cannot be made
     */
    public function render(string $html, ?string $paper = null, string $orientation = 'portrait'): string
    {
        $paper = strtolower($paper ?? $this->setting('pdf.paper', 'a4'));

        if (!\in_array($paper, self::PAPER, true) || !\in_array($orientation, ['portrait', 'landscape'], true)) {
            throw new PdfException(\sprintf('The paper must be one of %s and the orientation portrait or landscape.', implode(', ', self::PAPER)));
        }

        $cache = $this->setting('pdf.cache', sys_get_temp_dir() . '/trunk-pdf');

        if (!is_dir($cache) && !@mkdir($cache, 0o750, true) && !is_dir($cache)) {
            throw new PdfException(\sprintf('The PDF cache directory (pdf.cache) cannot be created: %s.', $cache));
        }

        $assets = $this->setting('pdf.assets', $cache);
        $options = new Options()
            ->setIsRemoteEnabled(false)
            ->setIsPhpEnabled(false)
            ->setIsJavascriptEnabled(false)
            ->setChroot([$assets])
            ->setFontCache($cache)
            ->setTempDir($cache)
            ->setDefaultPaperSize($paper);

        try {
            $dompdf = new Dompdf($options);
            $dompdf->setBasePath($assets);   // relative paths (images/logo.png) start in the assets directory
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper($paper, $orientation);
            $dompdf->render();
            $output = $dompdf->output();
        } catch (Throwable $e) {
            throw new PdfException('The PDF could not be made: ' . $e->getMessage(), 0, $e);
        }

        return $output !== '' ? $output : throw new PdfException('The PDF could not be made.');
    }

    private function setting(string $key, string $default): string
    {
        $value = $this->configuration->has($key) ? $this->configuration->get($key) : null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Pdf;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Configuration;
use Trunk\Pdf\Exception\PdfException;
use Trunk\Pdf\Pdf;
use Trunk\Support\Directory;

/**
 * HTML to PDF, and the lock-down that matters when the HTML carries what users typed: no fetching of
 * URLs, no PHP, local files only from the assets directory.
 */
final class PdfTest extends TestCase
{
    /** A valid 1x1 PNG (a broken one would be skipped by dompdf and prove nothing). */
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/trunk-pdf-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public/images', 0o755, true);
        mkdir($this->root . '/private', 0o755, true);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->root);
    }

    public function test_html_becomes_a_pdf_with_its_text_on_the_chosen_paper(): void
    {
        // Arrange
        $pdf = $this->pdf();

        // Act
        $a4 = $pdf->render('<h1>Invoice INV-7</h1><p>Total: 19.99</p>');
        $letter = $pdf->render('<p>Letter</p>', 'letter', 'landscape');

        // Assert
        self::assertStringStartsWith('%PDF-', $a4);
        self::assertStringContainsString('%%EOF', $a4);
        self::assertStringContainsString('Invoice INV-7', self::text($a4));
        self::assertStringContainsString('Total: 19.99', self::text($a4));
        self::assertMatchesRegularExpression('#/MediaBox \[0\.0+ 0\.0+ 595\.2\d* 841\.8\d*\]#', $a4, 'A4 portrait');
        self::assertMatchesRegularExpression('#/MediaBox \[0\.0+ 0\.0+ 792\.0+ 612\.0+\]#', $letter, 'US letter, landscape');
    }

    public function test_an_unknown_paper_or_orientation_is_refused(): void
    {
        // Arrange
        $pdf = $this->pdf();

        // Act & Assert
        foreach ([['b52', 'portrait'], ['a4', 'sideways']] as [$paper, $orientation]) {
            try {
                $pdf->render('<p>x</p>', $paper, $orientation);
                self::fail($paper . '/' . $orientation . ' should be refused');
            } catch (PdfException $e) {
                self::assertStringContainsString('a3, a4, a5, letter, legal', $e->getMessage());
            }
        }
    }

    public function test_a_url_in_the_html_is_never_fetched(): void
    {
        // Arrange: a real web server on this machine that records every request and answers at once
        $log = $this->root . '/requests.log';
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        self::assertNotSame('', $address);
        $server = proc_open([\PHP_BINARY, '-S', $address, \dirname(__DIR__, 2) . '/Support/request_recorder.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['TRUNK_RECORDER_LOG' => $log]);
        self::assertIsResource($server);

        for ($i = 0; $i < 50 && @file_get_contents('http://' . $address . '/ready') === false && !is_file($log); ++$i) {
            usleep(20_000);
        }

        @unlink($log);

        try {
            // Act
            $this->pdf()->render(\sprintf('<link rel="stylesheet" href="http://%1$s/style.css"><img src="http://%1$s/logo.png"><div style="background: url(http://%1$s/bg.png)">x</div>', $address));
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        // Assert
        self::assertFileDoesNotExist($log, 'the server received: ' . (is_file($log) ? (string) file_get_contents($log) : ''));
    }

    public function test_php_in_the_html_never_runs(): void
    {
        // Arrange
        $marker = $this->root . '/ran.txt';

        // Act
        // In the body, where dompdf evaluates such scripts when PHP is enabled (in <head> it never would)
        $this->pdf()->render(\sprintf('<html><body><p>x</p><script type="text/php">file_put_contents(%s, "ran");</script></body></html>', var_export($marker, true)));

        // Assert
        self::assertFileDoesNotExist($marker);
    }

    public function test_local_images_load_only_from_the_assets_directory(): void
    {
        // Arrange: the same picture inside and outside the assets directory
        file_put_contents($this->root . '/public/images/logo.png', (string) base64_decode(self::PNG_BASE64, true));
        file_put_contents($this->root . '/private/secret.png', (string) base64_decode(self::PNG_BASE64, true));
        $pdf = $this->pdf();

        // Act
        $inside = $pdf->render('<img src="' . $this->root . '/public/images/logo.png">');
        $relative = $pdf->render('<img src="images/logo.png">');
        $climbing = $pdf->render('<img src="../private/secret.png">');
        $outside = $pdf->render('<img src="' . $this->root . '/private/secret.png">');

        // Assert
        self::assertStringContainsString('/Subtype /Image', $inside);
        self::assertStringContainsString('/Subtype /Image', $relative, 'a relative path starts in the assets directory');
        self::assertStringNotContainsString('/Subtype /Image', $outside);
        self::assertStringNotContainsString('/Subtype /Image', $climbing, 'a relative path cannot climb out of the assets directory');
    }

    private function pdf(): Pdf
    {
        return new Pdf(new Configuration(['pdf' => ['paper' => 'a4', 'assets' => $this->root . '/public', 'cache' => $this->root . '/cache']]));
    }

    /**
     * The page content of a PDF, inflated, so the text it draws can be read.
     */
    private static function text(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $text = '';

        foreach ($streams[1] as $raw) {
            $inflated = @gzuncompress($raw);
            $text .= $inflated === false ? $raw : $inflated;
        }

        return $text;
    }
}

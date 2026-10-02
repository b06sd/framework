# PDF

`trunk package:install pdf` (it installs `dompdf/dompdf`). HTML goes in, a PDF comes out, in pure PHP: there is no browser or service to run on the server. Made for invoices, delivery notes, statements and reports.

## Making a PDF

Inject `Pdf`, give it HTML (a Tusk template is the natural source), and send the bytes as a download:

```php
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Pdf\Pdf;
use Trunk\Tusk\Renderer;

final readonly class InvoiceController
{
    public function __construct(private Pdf $pdf, private Renderer $views, private ResponseBuilder $responses) {}

    public function download(int $id): ResponseInterface
    {
        $invoice = /* load it, and check the user may see it */;
        $bytes = $this->pdf->render($this->views->render('invoices/pdf', ['invoice' => $invoice]));

        return $this->responses->download($bytes, 'invoice-' . $invoice->number . '.pdf', 'application/pdf');
    }
}
```

`render($html, paper: 'letter', orientation: 'landscape')` chooses the page: `a3`, `a4`, `a5`, `letter` or `legal`, portrait or landscape (the default paper is `pdf.paper`, `PDF_PAPER`). It returns the document as a string; `PdfException` says why one could not be made. To keep a copy, write the bytes to a [storage](storage.md) disk; to email it, attach them with `$email->attach($bytes, 'invoice.pdf', 'application/pdf')` ([mail](mail.md)).

## Writing the HTML

dompdf understands HTML and most of CSS 2.1, plus some CSS 3: tables, floats, borders, backgrounds, `@page` margins, page breaks (`page-break-before: always`), web fonts. It does not do flexbox or grid, so lay out with tables, as invoices usually are. The built-in DejaVu fonts cover most scripts; put `font-family: "DejaVu Sans"` on the body for text beyond Latin-1.

Images and stylesheets are loaded only from `pdf.assets` (your `public/` directory by default). A relative path starts there: `<img src="images/logo.png">` is `public/images/logo.png`.

## Safety

The HTML often carries what users typed (names, addresses, notes), so dompdf is locked down:

* **It never fetches a URL.** An `<img>` or stylesheet pointing at `http://...` is ignored, so a crafted document cannot make your server send requests into your network.
* **It never runs PHP or JavaScript** placed in the document.
* **It reads local files only from `pdf.assets`**, never the rest of the disk.

Tusk escapes everything you print in a template, so user text stays text. Large documents take time and memory to lay out; generate big reports in a [queue job](queue.md) and store the result rather than making a request wait.

Related: [Storage](storage.md) (keeping and serving files), [Views](views.md).

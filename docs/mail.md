# Mail

`trunk package:install mail` (it installs `symfony/mailer` and needs the `logging` capability). Trunk does not speak SMTP itself: sending goes through [Symfony Mailer](https://symfony.com/doc/current/mailer.html), so any SMTP server and the big providers work, chosen by one setting.

## Sending

Inject `Mailer` and send a Symfony `Email`:

```php
use Symfony\Component\Mime\Email;
use Trunk\Mail\Mailer;
use Trunk\Tusk\Renderer;

final readonly class ShippingNotifier
{
    public function __construct(private Mailer $mailer, private Renderer $views) {}

    public function shipped(Order $order, string $address): void
    {
        $this->mailer->send(new Email()
            ->to($address)
            ->subject('Your order shipped')
            ->text("Order {$order->id} is on its way.")
            ->html($this->views->render('emails/shipped', ['order' => $order])));   // resources/views/emails/shipped.tusk.php
    }
}
```

`Email` has everything a message needs: `to()`, `cc()`, `bcc()`, `replyTo()`, `from()`, `subject()`, `text()`, `html()`, `attachFromPath()`, `attach()`. A message without `from()` gets `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME`. HTML bodies come from Tusk templates through `Trunk\Tusk\Renderer` (the `tusk` capability), which escapes everything you put in them.

`send()` throws `MailException` when the message is incomplete (no recipient, no body) or the server or provider refused it or could not be reached. Its message is safe to show; the provider's own reason is in the log.

**In the background.** `send()` sends now, inside the call. To keep a request from waiting on the mail server, send from a [queue job](queue.md): dispatch `new SendShippingEmail($order->id)` and call the mailer in the job's `handle(Mailer $mailer, ...)`. A failed send then retries like any job.

## Where mail goes: MAIL_DSN

| `MAIL_DSN` | Sends through |
| --- | --- |
| `smtp://user:password@smtp.example.com:587` | any SMTP server (TLS is used when the server offers it; `smtps://...:465` for implicit TLS) |
| `ses+smtp://KEY:SECRET@default?region=eu-west-1` | Amazon SES (`composer require symfony/amazon-mailer`) |
| `mailgun+api://KEY:DOMAIN@default` | Mailgun (`composer require symfony/mailgun-mailer`) |
| `postmark+api://TOKEN@default` | Postmark (`composer require symfony/postmark-mailer`) |
| `sendgrid+api://KEY@default` | SendGrid (`composer require symfony/sendgrid-mailer`) |
| `file://default` | nothing: each email is kept whole as `storage/mail/*.eml` (development) |
| `null://null` | nothing: every email is discarded |

A provider DSN whose bridge is not installed is refused with the package to install. Characters such as `@`, `:` or `/` in a password must be URL-encoded in the DSN.

`MAIL_DSN` holds credentials, so it is a secret: read from the environment when the process starts, never written into `trunk build`'s output. Check the whole setup with:

```
trunk mail:test you@example.com
```

## Development

New projects start with `MAIL_DSN=file://default`: nothing is sent, and every email lands in `storage/mail` as an `.eml` file that any mail program opens, links included (click a password-reset link straight from it). The file transport is **refused in production**, so emails full of links and personal data never pile up on a server's disk; set a real `MAIL_DSN` before deploying.

## Safety

* Only the message id and the number of recipients are logged when an email is sent; never an address, a subject or a body. The SMTP conversation, including the login, never reaches your log.
* Addresses are validated, and a line break in a subject or a name cannot add a header or a recipient.
* `trunk build` refuses a `MAIL_FROM_ADDRESS` that is not an email address.

Related: [Queue](queue.md), [Views](views.md), [Configuration](configuration.md).

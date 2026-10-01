# Logging

Logging is built in (the `logging` capability, in every project type). Inject the standard PSR-3 `LoggerInterface` wherever you need it; there is no facade, no static logger and nothing Trunk-specific to learn. Every record is structured, carries the request's ids, and has secrets redacted before it is written.

## Writing a log record

```php
use Psr\Log\LoggerInterface;

final readonly class InvoiceService
{
    public function __construct(private LoggerInterface $logger) {}

    public function send(Invoice $invoice): void
    {
        // ...
        $this->logger->info('Invoice {id} sent to {email}', ['id' => $invoice->id, 'email' => $invoice->email]);
    }
}
```

`{name}` placeholders are filled from the context, and every context value is also kept as its own field, so a log platform can filter on `id` without parsing the message. Pass an exception as `exception` and it is recorded as its class, message, file, line and stack trace (up to 20 frames, never call arguments).

The levels, lowest first: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`.

## What every record carries

In production a record is one JSON object per line (this one was logged with `['id' => 7, 'api_token' => ...]` by a logger from `Logs`):

```json
{"timestamp":"2026-10-01T15:02:39.854Z","level":"INFO","message":"Invoice 7 sent","service":"shop","environment":"production","category":"App\\Services\\InvoiceService","requestId":"req_01M3VZTBFE8N87GZJXEZ1CH5HG","traceId":"da9c628675580a9097eca5522e90ac3a","kind":"http","id":7,"api_token":"[REDACTED]"}
```

| Field | From |
| --- | --- |
| `service` | `APP_NAME` (tells your applications apart in one log platform) |
| `environment` | `APP_ENV` |
| `category` | the logger's category, when it came from `Logs` (below) |
| `requestId`, `traceId`, `kind` | set automatically for each request (`kind: http`) and each queued job (`kind: job`, `requestId` `job_<id>`, plus `originRequestId`: the request that dispatched it); a request's `requestId` is returned to the client as `X-Request-Id`, and an incoming `traceparent` header is honoured |

A context key named `timestamp`, `level`, `message`, `service`, `environment` or `category` is kept with a trailing underscore (`level_`), so it can never pass for the real field.

## Levels per category

Most of the time one level for the whole application is right. When one part needs more detail (or less noise), give it a logger of its own: inject `Logs` and ask for one by name, usually the class.

```php
use Psr\Log\LoggerInterface;
use Trunk\Logging\Logs;

final readonly class StripeGateway
{
    private LoggerInterface $logger;

    public function __construct(Logs $logs)
    {
        $this->logger = $logs->for(self::class);
    }
}
```

Then set levels by prefix in `config/logging.php`:

```php
'level' => $runtime->variable('LOG_LEVEL', $local ? 'debug' : 'info'),
'levels' => [
    'App\Payments' => 'debug',                 // everything under App\Payments\...
    'App\Payments\Webhooks' => 'warning',      // ...except webhooks, which are noisy
],
```

* The most specific prefix wins, and a prefix only counts at a namespace (`\`) or dot boundary: `App\Payments` covers `App\Payments\StripeGateway` but not `App\PaymentsReport`. Names are compared without regard to case.
* A category with no matching prefix uses `level`.
* A category can be more verbose than `level` (debug for one module while the rest stays at info) as well as quieter.
* Any name of letters, digits and `_ . : \ -` works, so dotted names (`$logs->for('payments.stripe')`) are fine too. The name appears as `category` on every record, and in front of the message in the local line format: `2026-10-01T15:02:39.854Z DEBUG [App\Payments\StripeGateway] Charging card`.
* Injecting `LoggerInterface` directly still works exactly as before: that logger has no category and logs at `level`.

To change a category's level per environment, read it from `.env` like the main level: `'App\Payments' => $runtime->variable('LOG_LEVEL_PAYMENTS', 'info')`. `trunk build` refuses a `levels` entry whose name or level is not valid, with the fix in the message.

## Where records go

| Setting (`config/logging.php`) | Values | Default |
| --- | --- | --- |
| `channel` (`LOG_CHANNEL`) | `stderr`, `file` (`storage/logs/trunk-YYYY-MM-DD.log`, one file per UTC day), `null` | `file` locally, `stderr` in production |
| `format` | `json` (one object per line) or `line` (readable) | `line` locally, `json` in production |
| `level` (`LOG_LEVEL`) | any level above | `debug` locally, `info` in production |

`stderr` is the right choice in containers, under a process manager and for queue workers: the platform collects the stream. With `file`, rotate or ship the daily files yourself.

## Safety

* **Secrets are redacted.** Context keys containing `password`, `secret`, `token`, `authorization`, `cookie`, `api_key`, `private_key`, `credit_card`, `cvv` or `session_id` (at any depth, in any case, with or without `_`/`-`, so `csrf_token` and `X-Api-Key` count too) become `[REDACTED]`, and so do `Bearer …`/`Basic …` credentials, `password=…`-style pairs and `user:password@` in URLs inside any text. Add your own keys with `'redact' => ['iban']`.
* **Log lines cannot be forged.** Control characters and line breaks in messages and values are escaped, so a value a client sent can never start a new record.
* **Records are bounded:** a string is cut at 8 KB, a context array at 100 items and 6 levels deep.
* **Logging never breaks a request.** If the destination cannot be written, the record goes to PHP's `error_log` and your code carries on.

## What Trunk itself logs

Every failure goes through one error handler: an unexpected exception is an `error` with its trace; a client error (`4xx`) is a `notice`. A request no route answers (`404`/`405` from the router, which scanners send by the thousand) is one short `notice` naming the method and path (never the query string) instead of a trace. Turn `level` up to `warning` to hide client errors entirely.

## Coming from Microsoft.Extensions.Logging?

| .NET | Trunk |
| --- | --- |
| `ILogger` | `Psr\Log\LoggerInterface` |
| `ILogger<T>` / `ILoggerFactory.CreateLogger(name)` | `Logs::for(self::class)` |
| `"Logging": {"LogLevel": {"Default": ..., "MyApp.Payments": ...}}` | `level` and `levels` in `config/logging.php` |
| Message templates | `{name}` placeholders with structured context |
| `BeginScope` for request ids | automatic: `requestId`/`traceId` on every record |
| Providers | `channel` (`stderr`, `file`, `null`), one at a time |

Related: [Configuration reference](configuration.md), [Deployment](deployment.md) (collecting logs and health checks), [HTTP](http.md) (errors and request ids).

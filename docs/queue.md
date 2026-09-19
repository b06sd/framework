# Queue

`trunk package:install queue`, then `trunk queue:table && trunk migrate` (a `trunk_jobs` and a `trunk_failed_jobs` table).

## Jobs

`trunk make:job SendReceipt` creates `app/Jobs/SendReceipt.php` (jobs are discovered in `app/Jobs`).

```php
final readonly class SendReceipt implements Job
{
    public function __construct(public int $postId) {}               // the constructor IS the payload

    public function handle(LoggerInterface $logger, Mailer $mailer): void   // services injected per job
    { ... }

    public static function options(): JobOptions
    {
        return new JobOptions(tries: 3, backoff: [10, 60, 300], timeout: 60, queue: 'default');
    }
}
```

The payload may contain ints, floats, strings, bools, arrays, backed enums and `DateTimeImmutable`; **pass ids, not entities** (`max_payload` is 64 KB). A job with an unsafe constructor fails validation, in `trunk build` and in development, with an actionable message. Payloads are encoded and decoded by generated code and are never PHP-serialised.

```php
$queue->dispatch(new SendReceipt($id));                  // returns the job id
$queue->dispatch(new SendReceipt($id), delay: 60);       // run in a minute
$queue->dispatchMany($jobs);                              // one insert
$queue->size('default');
```

A job carries the request id and trace id of the request that queued it (`originRequestId` in logs).

## Workers

```bash
trunk queue:work                                  # forever
trunk queue:work --queue=critical,default --max-jobs=1000 --max-time=3600 --memory=256
trunk queue:work --once            # or --stop-when-empty
```

* A job runs in its **own container scope**; per-request state is reset after every job.
* Retries with your `backoff`; after the last try the job moves to the failed table. Throw an exception implementing `PermanentFailure` (or `UnrecoverableJob`) to fail immediately.
* **A job that leaves a database transaction open fails** (`TransactionLeftOpen`) and the transaction is rolled back.
* A claimed job that never finished becomes claimable again after `visibility_timeout` (default 600 s, which must exceed every job timeout by 30 s or the build fails, so a running job cannot be claimed twice).
* With `pcntl`, a job timeout is enforced and SIGTERM/SIGINT finish the current job then exit. Memory limit, job count and runtime are checked between jobs; the worker exits cleanly at a limit and **should run under a supervisor that restarts it** (systemd, supervisord, a container restart policy).
* Claims are atomic, so several workers can share one queue (tested with parallel processes on SQLite, MySQL and PostgreSQL: every job runs exactly once).

`queue:failed`, `queue:retry <id|all>`, `queue:flush`. Failure messages can contain user data, so they are stored only when debugging.

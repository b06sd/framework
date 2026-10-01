# Pipelines

`trunk package:install pipeline` (it needs `queue`). Move data from one place to another in a chain of small, explicit stages — a source, zero or more transforms, a sink — streamed a chunk at a time so a dataset larger than memory never has to fit in memory at once, and durable: each chunk is a queue job, so a run survives a crash and a failure retries like any other job instead of losing your place.

## Define a pipeline

```php
// app/Pipelines/ImportCustomers.php
<?php

declare(strict_types=1);

namespace App\Pipelines;

use Trunk\Database\Connection\Connection;
use Trunk\Foundation\Runtime;
use Trunk\Pipeline\Pipeline;
use Trunk\Pipeline\PipelineBuilder;
use Trunk\Pipeline\Sink\DatabaseSink;
use Trunk\Pipeline\Source\CsvSource;

final readonly class ImportCustomers implements Pipeline
{
    public function __construct(private Connection $db, private Runtime $runtime) {}

    public function define(PipelineBuilder $pipeline): void
    {
        $pipeline->from(new CsvSource($this->runtime->basePath . '/storage/imports/customers.csv'))
            ->through(new TrimFields())
            ->into(new DatabaseSink($this->db, 'customers'))
            ->chunk(500);
    }
}
```

`trunk make:pipeline ImportCustomers` writes this file (with `ArraySource`/`ArraySink` to start, since a real source and sink always need choosing). Unlike an entity map or a job, a `Pipeline` is resolved from the container rather than constructed with `new` — inject whatever a source or sink needs (a `Connection`, an HTTP client) through the pipeline's own constructor.

`through()` is optional and repeatable; a `Stage` returns the transformed record, or `null` to drop it:

```php
final readonly class TrimFields implements Stage
{
    public function process(mixed $record): mixed
    {
        return array_map('trim', $record);
    }
}
```

## Built-in sources and sinks

| | Reads from / writes to | Notes |
| --- | --- | --- |
| `Source\CsvSource` | A CSV file | Streams with `fgetcsv()`, never loads the file fully. Re-reads from the start each chunk (a CSV has no cheap way to seek to "row N"); fine for the moderate files most imports are. |
| `Source\ArraySource`, `Sink\ArraySink` | An in-memory list | For tests, or a dataset already small enough to be an array. |
| `Sink\DatabaseSink` | A table, via `Connection` | One batched `INSERT` per chunk. |
| `Source\ApiSource`, `Sink\ApiSink` | A paginated JSON API | Bring your own PSR-18 client and PSR-17 factories (`psr/http-client` is a real dependency of this package; `guzzlehttp/guzzle` is a suggested concrete client). See "Connection reuse" below. |

Write your own by implementing `Source`, `Stage` or `Sink` directly — each is one method.

## How a run works

`trunk pipeline:run ImportCustomers` inserts one row into `trunk_pipeline_runs` and dispatches one job. From there, `trunk queue:work --queue=pipelines` does the work: each chunk reads, transforms, writes, advances the run, and dispatches the next chunk itself — until the source returns fewer records than the chunk size, which marks the run `completed`. A pipeline's chunks always run on the `pipelines` queue (not `default`), so you can give them their own worker if you want them isolated from the rest of your queue traffic.

```
$ trunk pipeline:status
#  Pipeline                       Status     Cursor  Records  Started              Completed
1  App\Pipelines\ImportCustomers  completed  4000    4000     2026-01-01 03:00:01  2026-01-01 03:00:14
```

A chunk that throws fails like any other job — the queue's own retry/backoff applies, and it eventually lands in `trunk_failed_jobs` if it keeps failing (`trunk queue:failed`, `trunk queue:retry`). One bad record fails its whole chunk; it is not skipped and routed elsewhere.

If a run is stuck `running` with no chunk in flight (a crash between one chunk succeeding and the next being dispatched — rare, but possible), `trunk pipeline:resume <run-id>` re-dispatches one chunk at the run's last recorded cursor.

## Connection reuse ("trunking" an API source)

A `Pipeline` instance is resolved once per worker process and reused for every chunk it processes — the same way `Trunk\Database\Connection\ConnectionManager` already reuses one database connection for a process's whole lifetime, not one per query. That means an `ApiSource`/`ApiSink` built with a singleton-bound PSR-18 client gets the same benefit for free: the same client, and its keep-alive connection, serves every request in the run instead of opening a new one each time.

## What is deliberately not here

* **No build-time validation of a pipeline's shape.** Because a `Pipeline` is container-resolved (a `Source`/`Sink` often needs a real service), it cannot safely be constructed at `trunk build` time the way an entity map or job can. A broken pipeline (no `into()`, say) surfaces when `pipeline:run` actually runs it — the same category of gap a route or controller already has.
* **No parallelism within one run.** Chunks are dispatched one at a time, in order; a source that could report a count upfront could parallelize (a natural v2), but not every source can do that cheaply (a paginated API, for one).

Related: [Queue](queue.md), [Database](database.md), [CLI](cli.md).

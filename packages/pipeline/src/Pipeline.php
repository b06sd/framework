<?php

declare(strict_types=1);

namespace Trunk\Pipeline;

/**
 * A named, container-resolved description of moving data from one place to another:
 *
 *     final readonly class ImportCustomers implements Pipeline
 *     {
 *         public function __construct(private Connection $db) {}
 *
 *         public function define(PipelineBuilder $pipeline): void
 *         {
 *             $pipeline->from(new CsvSource('customers.csv'))
 *                 ->through(new TrimFields())
 *                 ->into(new DatabaseSink($this->db, 'customers'))
 *                 ->chunk(500);
 *         }
 *     }
 *
 * Unlike EntityMap or Job, a Pipeline is resolved from the container rather than constructed with
 * `new` — there is no build-time code generation to justify that restriction here, and a Source or
 * Sink often genuinely needs an injected service (a database connection, an HTTP client), which only
 * works if the Pipeline itself can receive one and hand it down.
 *
 * @api
 */
interface Pipeline
{
    public function define(PipelineBuilder $pipeline): void;
}

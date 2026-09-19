<?php

declare(strict_types=1);

namespace Trunk\Orm\Diagnostics;

use Trunk\Database\Connection\QueryLog;

/**
 * Finds statements that repeat with the same shape. The query log holds SQL with placeholders and
 * binding counts only, so this never sees (and can never leak) a value. Development tooling: the
 * log is off in production, and so is this.
 */
final readonly class NPlusOneDetector
{
    public function __construct(private int $threshold = 5) {}

    /**
     * @return list<NPlusOneFinding>
     */
    public function detect(QueryLog $log): array
    {
        $counts = [];

        foreach ($log->entries() as $entry) {
            $shape = preg_replace('/\?(?:\s*,\s*\?)+/', '?…', $entry['sql']) ?? $entry['sql'];

            if (!str_starts_with($shape, 'SELECT')) {
                continue;
            }

            $counts[$shape] = ($counts[$shape] ?? 0) + 1;
        }

        $findings = [];

        foreach ($counts as $sql => $count) {
            if ($count >= $this->threshold) {
                $findings[] = new NPlusOneFinding($sql, $count);
            }
        }

        usort($findings, static fn(NPlusOneFinding $a, NPlusOneFinding $b): int => $b->count <=> $a->count);

        return $findings;
    }
}

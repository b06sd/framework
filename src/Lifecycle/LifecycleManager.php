<?php

declare(strict_types=1);

namespace Trunk\Lifecycle;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resets every LifecycleAware service (those tagged `trunk.lifecycle`) between units of work, in
 * reverse registration order. Each reset is isolated: one that fails is logged and the rest still
 * run. With nothing tagged it does nothing, so calling it after every request or job is free.
 */
final class LifecycleManager
{
    /** @var list<LifecycleAware>|null */
    private ?array $services = null;

    /**
     * @param iterable<LifecycleAware> $registered
     */
    public function __construct(private readonly iterable $registered = [], private readonly ?LoggerInterface $logger = null) {}

    /**
     * @return list<Throwable> the resets that failed (each already logged), for a caller that must
     *                         act on one, such as a UnitOfWorkDiscarded
     */
    public function cleanup(): array
    {
        $this->services ??= array_values([...$this->registered]);
        $failures = [];

        foreach (array_reverse($this->services) as $service) {
            try {
                $service->reset();
            } catch (Throwable $e) {
                $failures[] = $e;
                $this->report($service, $e);
            }
        }

        return $failures;
    }

    private function report(LifecycleAware $service, Throwable $error): void
    {
        try {
            $this->logger?->warning('A lifecycle reset failed; the other resets still ran.', ['service' => $service::class, 'exception' => $error]);
        } catch (Throwable) {
            // Cleanup must never throw into the request or job that just finished.
        }
    }
}

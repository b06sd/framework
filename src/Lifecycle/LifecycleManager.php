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
     * @return int how many resets failed
     */
    public function cleanup(): int
    {
        $this->services ??= array_values([...$this->registered]);
        $failed = 0;

        foreach (array_reverse($this->services) as $service) {
            try {
                $service->reset();
            } catch (Throwable $e) {
                ++$failed;
                $this->report($service, $e);
            }
        }

        return $failed;
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

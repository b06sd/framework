<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use Trunk\Logging\RequestContext;
use Trunk\Logging\RequestId;

/**
 * Who queued a job: the request id and trace id current at dispatch, stored beside the job so the
 * worker can continue the same trace and log which request caused the work. The stored text is
 * data from the database, so it is parsed strictly and anything unexpected is dropped.
 */
final class Origin
{
    public static function encode(?RequestContext $context): ?string
    {
        return $context === null ? null : json_encode(['requestId' => $context->requestId, 'traceId' => $context->traceId], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{requestId: string, traceId: string}|null
     */
    public static function parse(?string $origin): ?array
    {
        if ($origin === null || $origin === '' || \strlen($origin) > 512) {
            return null;
        }

        $data = json_decode($origin, true, 4);

        if (!\is_array($data)) {
            return null;
        }

        $requestId = \is_string($data['requestId'] ?? null) ? RequestId::accept($data['requestId']) : null;
        $traceId = $data['traceId'] ?? null;

        if ($requestId === null || !\is_string($traceId) || preg_match('/^[0-9a-f]{32}$/D', $traceId) !== 1) {
            return null;
        }

        return ['requestId' => $requestId, 'traceId' => $traceId];
    }
}

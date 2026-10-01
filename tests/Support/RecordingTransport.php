<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail transport that keeps what it is given, or fails with `$failWith`.
 */
final class RecordingTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $sent = [];

    /** @var list<Envelope> */
    public array $envelopes = [];

    public ?TransportException $failWith = null;

    public function __toString(): string
    {
        return 'recording://';
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        if (!$message instanceof Email) {
            throw new TransportException('RecordingTransport only takes Email messages.');
        }

        $envelope ??= Envelope::create($message);
        $this->sent[] = $message;
        $this->envelopes[] = $envelope;

        return new SentMessage($message, $envelope);
    }
}

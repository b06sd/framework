<?php

declare(strict_types=1);

namespace Trunk\Mail;

use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Trunk\Mail\Exception\MailException;

/**
 * Sends email through the transport configured as `MAIL_DSN`: SMTP, or a provider such as Amazon SES,
 * Mailgun, Postmark or SendGrid through Symfony Mailer. Build the message with Symfony's own `Email`:
 *
 *   $mailer->send(new Email()->to($user->email)->subject('Your order shipped')->text($text)->html($html));
 *
 * A message with no From gets `mail.from`. Sending happens now, inside the call; to send without making
 * a request wait, call this from a queue job. Only the message id and the number of recipients are
 * logged, never a subject, an address or a body (they can hold personal data and tokens).
 *
 * @api
 */
final readonly class Mailer
{
    /** @internal wired by MailModule */
    public function __construct(
        private TransportInterface $transport,
        private Address $from,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @throws MailException when the message is incomplete (no recipient, no body) or could not be sent
     */
    public function send(Email $email): void
    {
        $message = $email->getFrom() === [] ? (clone $email)->from($this->from) : $email;
        $recipients = \count($message->getTo()) + \count($message->getCc()) + \count($message->getBcc());

        try {
            // A recipient and a body: a programming error, caught before anything is handed to the transport.
            $message->ensureValidity();
        } catch (LogicException $e) {
            throw new MailException('The email is incomplete: ' . $e->getMessage(), 0, $e);
        }

        try {
            $sent = $this->transport->send($message);
        } catch (TransportExceptionInterface $e) {
            $this->logger?->error('Email could not be sent.', ['exception' => $e, 'recipients' => $recipients]);

            throw new MailException('The email could not be sent: the mail server or provider refused it or could not be reached. The reason is in the log.', 0, $e);
        }

        $this->logger?->info('Email sent.', ['messageId' => $sent?->getMessageId(), 'recipients' => $recipients]);
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Mail\Transport;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * `MAIL_DSN=file://default`, for development: every email is kept, whole, as an .eml file in
 * storage/mail (open it with any mail program, links and all) instead of being sent. The file name is
 * a timestamp and random id, never anything from the message. Refused in production.
 *
 * @internal built by MailerFactory
 */
final class FileTransport extends AbstractTransport
{
    public function __construct(private readonly string $directory)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'file://default';
    }

    protected function doSend(SentMessage $message): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            throw new TransportException('The mail directory could not be created.');
        }

        $file = \sprintf('%s/%s-%s.eml', $this->directory, gmdate('Ymd-His'), bin2hex(random_bytes(6)));

        if (@file_put_contents($file, $message->toString(), \LOCK_EX) === false) {
            throw new TransportException('The email could not be written to the mail directory.');
        }

        @chmod($file, 0o640);
    }
}

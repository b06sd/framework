<?php

declare(strict_types=1);

namespace Trunk\Mail;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as InvalidDsnException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;
use Trunk\Mail\Exception\MailException;
use Trunk\Mail\Transport\FileTransport;

/**
 * Builds the Mailer from `mail.*`. Nothing connects here: SMTP opens its connection on the first send.
 *
 * @internal wired by MailModule
 */
final readonly class MailerFactory
{
    public function create(Configuration $configuration, Runtime $runtime, ?LoggerInterface $logger = null): Mailer
    {
        return new Mailer($this->transport($configuration->string('mail.dsn'), $runtime, $logger), self::from($configuration), $logger);
    }

    /**
     * The configured sender, checked (`trunk build` reports the same problem with the fix).
     */
    public static function from(Configuration $configuration): Address
    {
        $address = $configuration->has('mail.from.address') ? $configuration->get('mail.from.address') : null;
        $name = $configuration->has('mail.from.name') ? $configuration->get('mail.from.name') : '';

        if (!\is_string($address) || filter_var($address, \FILTER_VALIDATE_EMAIL) === false || !\is_string($name) || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new MailException('mail.from.address must be an email address (MAIL_FROM_ADDRESS in .env), and mail.from.name plain text on one line (MAIL_FROM_NAME).');
        }

        try {
            return new Address($address, $name);
        } catch (RfcComplianceException) {
            throw new MailException('mail.from.address must be an email address (MAIL_FROM_ADDRESS in .env).');
        }
    }

    private function transport(#[SensitiveParameter] string $dsn, Runtime $runtime, ?LoggerInterface $logger): TransportInterface
    {
        if (str_starts_with($dsn, 'file://')) {
            if ($runtime->environment === Environment::Production) {
                // Emails carry password-reset links and personal data: they must not pile up on a server's disk.
                throw new MailException('MAIL_DSN is file:// (development only) in production. Set it to your mail server or provider, e.g. smtp://user:password@smtp.example.com:587.');
            }

            return new FileTransport($runtime->basePath . '/storage/mail');
        }

        try {
            return Transport::fromDsn($dsn, logger: $logger);
        } catch (UnsupportedSchemeException $e) {
            // Names the bridge package to install; carries no credentials.
            throw new MailException('MAIL_DSN: ' . $e->getMessage(), 0, $e);
        } catch (InvalidDsnException $e) {
            throw new MailException('MAIL_DSN is not a valid mail DSN, e.g. smtp://user:password@smtp.example.com:587 or null://null.', 0, $e);
        }
    }
}

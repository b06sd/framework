<?php

declare(strict_types=1);

namespace Trunk\Mail\Console;

use Symfony\Component\Mime\Email;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Mail\Exception\MailException;
use Trunk\Mail\Mailer;

/**
 * `trunk mail:test you@example.com`: sends one short email through the configured transport, the
 * quickest way to see that MAIL_DSN and the sender work.
 */
final readonly class MailTestCommand implements Command
{
    public function __construct(private Mailer $mailer) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('mail:test', 'Send a test email to check the mail settings', ['address' => 'where to send it'], [], 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $address = (string) $input->argument(0);

        if (filter_var($address, \FILTER_VALIDATE_EMAIL) === false) {
            $output->failure('Give an email address, e.g. `trunk mail:test you@example.com`.');

            return 1;
        }

        try {
            $this->mailer->send(new Email()->to($address)->subject('Trunk mail test')->text("This is a test email from your Trunk application.\nIf you can read it, MAIL_DSN and MAIL_FROM_ADDRESS work."));
        } catch (MailException $e) {
            $output->failure($e->getMessage());

            return 1;
        }

        $output->success(\sprintf('Sent a test email to %s.', $address));

        return 0;
    }
}

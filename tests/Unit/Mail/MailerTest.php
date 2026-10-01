<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Logging\JsonFormatter;
use Trunk\Logging\StructuredLogger;
use Trunk\Mail\Exception\MailException;
use Trunk\Mail\Mailer;
use Trunk\Mail\MailerFactory;
use Trunk\Mail\MailModule;
use Trunk\Support\Directory;
use Trunk\Tests\Support\ContainerModes;
use Trunk\Tests\Support\RecordingLogHandler;
use Trunk\Tests\Support\RecordingTransport;

final class MailerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/trunk-mail-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->base);
    }

    public function test_the_configured_sender_is_used_when_a_message_has_none_and_the_callers_email_is_not_changed(): void
    {
        // Arrange
        [$mailer, $transport] = $this->mailer();
        $email = new Email()->to('ada@example.com')->subject('Hi')->text('Hello');

        // Act
        $mailer->send($email);

        // Assert
        self::assertSame(['shop@example.com', 'Shop'], [$transport->sent[0]->getFrom()[0]->getAddress(), $transport->sent[0]->getFrom()[0]->getName()]);
        self::assertSame([], $email->getFrom(), 'the message passed in is left as it was');
    }

    public function test_an_incomplete_message_is_refused_before_the_transport_sees_it(): void
    {
        // Arrange
        [$mailer, $transport] = $this->mailer();

        // Act & Assert
        foreach (['no recipient' => new Email()->text('x'), 'no body' => new Email()->to('ada@example.com')] as $case => $email) {
            try {
                $mailer->send($email);
                self::fail($case . ' should be refused');
            } catch (MailException $e) {
                self::assertStringStartsWith('The email is incomplete:', $e->getMessage(), $case);
            }
        }

        self::assertSame([], $transport->sent);
    }

    public function test_logs_carry_the_message_id_and_recipient_count_never_addresses_subjects_or_bodies(): void
    {
        // Arrange
        [$mailer, , $log] = $this->mailer();

        // Act
        $mailer->send(new Email()->to('ada@example.com')->cc('bob@example.com')->subject('Reset your password')->text('https://shop.test/reset?token=s3cr3t'));
        $record = self::record($log, 0);
        $all = implode('', $log->lines);

        // Assert
        self::assertSame(['INFO', 'Email sent.', 2], [$record['level'] ?? null, $record['message'] ?? null, $record['recipients'] ?? null]);
        self::assertIsString($record['messageId'] ?? null);
        self::assertStringNotContainsString('ada@', $all);
        self::assertStringNotContainsString('Reset', $all);
        self::assertStringNotContainsString('s3cr3t', $all);
    }

    public function test_a_transport_failure_is_one_generic_exception_with_the_reason_in_the_log(): void
    {
        // Arrange
        [$mailer, $transport, $log] = $this->mailer();
        $transport->failWith = new TransportException('550 mailbox unavailable');

        // Act
        try {
            $mailer->send(new Email()->to('ada@example.com')->text('x'));
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            // Assert
            self::assertStringNotContainsString('mailbox', $e->getMessage(), 'the client-facing message never echoes the provider');
            self::assertStringContainsString('The reason is in the log', $e->getMessage());
            self::assertSame('ERROR', self::record($log, 0)['level'] ?? null);
            self::assertStringContainsString('550 mailbox unavailable', $log->lines[0] ?? '');
        }
    }

    public function test_a_header_injection_attempt_cannot_add_a_recipient(): void
    {
        // Arrange
        [$mailer, $transport] = $this->mailer();

        // Act: a subject and a display name carrying a line break and a Bcc header
        $mailer->send(new Email()->to(new Address('ada@example.com', "Ada\r\nBcc: evil@example.com"))->subject("Hello\r\nBcc: evil@example.com")->text('x'));
        $raw = $transport->sent[0]->toString();

        // Assert: the envelope (who it is delivered to) and the headers have only the real recipient
        self::assertSame(['ada@example.com'], array_map(static fn(Address $a): string => $a->getAddress(), $transport->envelopes[0]->getRecipients()));
        self::assertDoesNotMatchRegularExpression('/^Bcc:/mi', $raw);

        $this->expectException(Throwable::class);
        new Email()->to("ada@example.com\r\nBcc: evil@example.com");
    }

    public function test_the_file_transport_keeps_whole_messages_for_development_and_is_refused_in_production(): void
    {
        // Arrange
        $config = $this->configuration('file://default');
        $development = new MailerFactory()->create($config, new Runtime(Environment::Local, true, $this->base));

        // Act
        $development->send(new Email()->to('ada@example.com')->subject('Reset')->text('https://shop.test/reset?token=abc'));
        $files = glob($this->base . '/storage/mail/*.eml') ?: [];

        // Assert
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('#/\d{8}-\d{6}-[0-9a-f]{12}\.eml$#', $files[0], 'named by time and a random id, never by the message');
        self::assertStringContainsString('token=abc', quoted_printable_decode((string) file_get_contents($files[0])), 'links are kept whole (a mail program decodes the body), so they can be clicked');
        self::assertSame('0640', substr(\sprintf('%o', fileperms($files[0])), -4));

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('file:// (development only) in production');
        new MailerFactory()->create($config, new Runtime(Environment::Production, false, $this->base));
    }

    public function test_dsn_problems_name_the_fix_and_never_echo_credentials(): void
    {
        // Arrange
        $runtime = new Runtime(Environment::Production, false, $this->base);
        $cases = [
            'mailgun+api://KEY_SHOULD_NOT_SHOW:example.com@default' => 'symfony/mailgun-mailer',
            'not a dsn with secret-password' => 'not a valid mail DSN',
        ];

        foreach ($cases as $dsn => $expected) {
            // Act
            try {
                new MailerFactory()->create($this->configuration($dsn), $runtime);
                self::fail($dsn . ' should be refused');
            } catch (MailException $e) {
                // Assert
                self::assertStringContainsString($expected, $e->getMessage());
                self::assertStringNotContainsString('KEY_SHOULD_NOT_SHOW', $e->getMessage());
                self::assertStringNotContainsString('secret-password', $e->getMessage());
            }
        }
    }

    public function test_the_build_refuses_a_sender_that_is_not_an_email_address(): void
    {
        // Arrange
        $runtime = new Runtime(Environment::Production, false, $this->base);

        foreach (['not-an-address', "ops@example.com\r\nBcc: x@example.com"] as $address) {
            $context = new BuildContext(new ModuleManifest([]), $runtime, new Configuration(['mail' => ['dsn' => 'null://null', 'from' => ['address' => $address, 'name' => 'Shop']]]));

            // Act & Assert
            try {
                new MailModule()->plan($context);
                self::fail('Expected a build error for ' . json_encode($address));
            } catch (CompilationException $e) {
                self::assertStringContainsString('config/mail.php: mail.from.address must be an email address', $e->errors[0]);
            }
        }
    }

    public function test_the_mailer_is_wired_in_both_container_modes(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $builder): void {
            new LoggingModule()->register($builder);
            new MailModule()->register($builder);
        }, ['logging' => ['channel' => 'null'], 'mail' => ['dsn' => 'null://null', 'from' => ['address' => 'shop@example.com', 'name' => 'Shop']]], [Mailer::class]);

        foreach ($containers as $mode => $container) {
            // Act
            $mailer = $container->get(Mailer::class);

            // Assert
            self::assertInstanceOf(Mailer::class, $mailer, $mode);
            $mailer->send(new Email()->to('ada@example.com')->text('x'));
        }
    }

    /**
     * @return array{Mailer, RecordingTransport, RecordingLogHandler}
     */
    private function mailer(): array
    {
        $transport = new RecordingTransport();
        $log = new RecordingLogHandler();

        return [new Mailer($transport, new Address('shop@example.com', 'Shop'), new StructuredLogger(new JsonFormatter(), $log, 'debug')), $transport, $log];
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(RecordingLogHandler $log, int $index): array
    {
        $decoded = json_decode($log->lines[$index] ?? '{}', true, 32, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? array_filter($decoded, is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }

    private function configuration(string $dsn): Configuration
    {
        return new Configuration(['mail' => ['dsn' => $dsn, 'from' => ['address' => 'shop@example.com', 'name' => 'Shop']]]);
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;
use Trunk\Logging\JsonFormatter;
use Trunk\Logging\StructuredLogger;
use Trunk\Mail\Exception\MailException;
use Trunk\Mail\MailerFactory;
use Trunk\Tests\Support\RecordingLogHandler;

/**
 * Real SMTP: the mailer talks to a small SMTP server in another process (tests/Support/smtp_sink.php),
 * with a login, exactly as it would to a mail provider. The password must never reach the log, even
 * at debug level.
 */
final class SmtpDeliveryTest extends TestCase
{
    private const string PASSWORD = 'sup3r-s3cret-pw';

    /** @var resource|null */
    private $process;

    private string $transcript;

    protected function setUp(): void
    {
        $this->transcript = sys_get_temp_dir() . '/trunk-smtp-' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        @unlink($this->transcript);
    }

    public function test_an_email_is_delivered_over_smtp_with_a_login_and_the_password_never_logged(): void
    {
        // Arrange
        $port = $this->sink('accept');
        [$mailer, $log] = $this->mailer($port);

        // Act
        $mailer->send(new Email()->to('ada@example.com')->subject('Your order shipped')->text('Order 7 is on its way.')->html('<p>Order 7 is on its way.</p>'));
        $this->finish();
        $transcript = (string) file_get_contents($this->transcript);

        // Assert: the server saw the login, the envelope and the whole message
        self::assertStringContainsString('AUTH PLAIN', $transcript);
        self::assertStringContainsString('MAIL FROM:<shop@example.com>', $transcript);
        self::assertStringContainsString('RCPT TO:<ada@example.com>', $transcript);
        self::assertStringContainsString('Subject: Your order shipped', $transcript);
        self::assertStringContainsString('Order 7 is on its way.', $transcript);
        self::assertStringContainsString('"message":"Email sent."', implode('', $log->lines));
        $this->assertNoCredentials($log);
    }

    public function test_a_refused_recipient_is_a_mail_exception_with_the_servers_reason_in_the_log_only(): void
    {
        // Arrange
        $port = $this->sink('reject');
        [$mailer, $log] = $this->mailer($port);

        // Act
        try {
            $mailer->send(new Email()->to('nobody@example.com')->subject('x')->text('x'));
            self::fail('Expected a MailException.');
        } catch (MailException $e) {
            // Assert
            self::assertStringNotContainsString('mailbox unavailable', $e->getMessage());
            self::assertStringContainsString('mailbox unavailable', implode('', $log->lines), 'the reason is logged for whoever runs the app');
            $this->assertNoCredentials($log);
        }
    }

    private function assertNoCredentials(RecordingLogHandler $log): void
    {
        $all = implode('', $log->lines);
        self::assertStringNotContainsString(self::PASSWORD, $all);
        self::assertStringNotContainsString(base64_encode("shopuser\0shopuser\0" . self::PASSWORD), $all);
        self::assertStringNotContainsString(base64_encode("\0shopuser\0" . self::PASSWORD), $all);
    }

    /**
     * @return array{\Trunk\Mail\Mailer, RecordingLogHandler}
     */
    private function mailer(int $port): array
    {
        $log = new RecordingLogHandler();
        $configuration = new Configuration(['mail' => ['dsn' => \sprintf('smtp://shopuser:%s@127.0.0.1:%d', self::PASSWORD, $port), 'from' => ['address' => 'shop@example.com', 'name' => 'Shop']]]);
        $mailer = new MailerFactory()->create($configuration, new Runtime(Environment::Production, false, sys_get_temp_dir()), new StructuredLogger(new JsonFormatter(), $log, 'debug'));

        return [$mailer, $log];
    }

    private function sink(string $mode): int
    {
        $process = proc_open([\PHP_BINARY, __DIR__ . '/../../Support/smtp_sink.php', $this->transcript, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->process = $process;
        $port = (int) fgets($pipes[1]);

        if ($port === 0) {
            // Only now: reading stderr waits for the sink to exit.
            self::fail('The SMTP sink did not start: ' . stream_get_contents($pipes[2]));
        }

        return $port;
    }

    private function finish(): void
    {
        // The transport says QUIT when it is destroyed; wait for the sink to see it.
        gc_collect_cycles();

        for ($i = 0; $i < 50 && \is_resource($this->process) && proc_get_status($this->process)['running']; ++$i) {
            usleep(20_000);
        }
    }
}

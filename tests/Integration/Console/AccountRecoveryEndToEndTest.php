<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Testing\TestApp;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * The password-reset flow from docs/auth.md, in a project made by the real `trunk new`: the form queues
 * a job for every address alike, a real worker sends the email (the file transport keeps it), and the
 * link from that email sets the new password once.
 */
final class AccountRecoveryEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    #[RunInSeparateProcess]
    public function test_forgot_password_mails_a_working_one_time_link_and_says_nothing_about_unknown_addresses(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('accounts', 'api');

        foreach (['console', 'auth', 'queue', 'mail'] as $capability) {
            [$code, $out, $err] = $project->trunk(['package:install', $capability]);
            self::assertSame(0, $code, $capability . ': ' . $out . $err);
        }

        $project->trunk(['auth:table', '--users']);
        $project->trunk(['queue:table']);
        [$migrateCode, $migrateOut, $migrateErr] = $project->trunk(['migrate']);
        self::assertSame(0, $migrateCode, $migrateOut . $migrateErr);
        $database = new ConnectionFactory()->make('seed', ['driver' => 'sqlite', 'database' => $project->directory . '/storage/database.sqlite']);
        $database->table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => (string) password_hash('correct horse battery', \PASSWORD_ARGON2ID), 'session_version' => '1']);
        $this->writeApplication($project->directory);
        require_once $project->directory . '/vendor/autoload.php';
        $client = TestApp::client($project->directory);

        // Act: ask for a link for a real and an unknown address, then let a worker run the jobs
        $known = $client->post('/forgot-password', ['email' => 'ada@example.com']);
        $unknown = $client->post('/forgot-password', ['email' => 'nobody@example.com']);
        [$workCode, $workOut, $workErr] = $project->trunk(['queue:work', '--stop-when-empty'], environment: ['APP_ENV' => 'local']);
        $mails = glob($project->directory . '/storage/mail/*.eml') ?: [];
        $body = quoted_printable_decode((string) file_get_contents($mails[0] ?? ''));
        preg_match('/token=([A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{43})/', $body, $m);
        $token = $m[1] ?? '';
        $reset = $client->post('/reset-password', ['token' => $token, 'password' => 'a brand new passphrase']);
        $replay = $client->post('/reset-password', ['token' => $token, 'password' => 'someone elses passphrase']);
        $hash = $database->table('users')->where('email', 'ada@example.com')->value('password');

        // Assert: both answers are identical; only the real account got mail
        self::assertSame([200, 200], [$known->status(), $unknown->status()]);
        self::assertSame($known->body(), $unknown->body());
        self::assertSame(0, $workCode, $workOut . $workErr);
        self::assertCount(1, $mails, 'one email, to the account that exists');
        self::assertStringContainsString('To: ada@example.com', $body);
        self::assertNotSame('', $token, $body);
        $reset->assertOk();
        self::assertSame(400, $replay->status(), 'the link works once');
        self::assertTrue(password_verify('a brand new passphrase', \is_string($hash) ? $hash : ''));
    }

    private function writeApplication(string $root): void
    {
        file_put_contents($root . '/app/Jobs/SendPasswordResetLink.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Jobs;

            use Symfony\Component\Mime\Email;
            use Trunk\Auth\Password\PasswordReset;
            use Trunk\Mail\Mailer;
            use Trunk\Queue\Job\Job;

            final readonly class SendPasswordResetLink implements Job
            {
                public function __construct(public string $email) {}

                public function handle(PasswordReset $resets, Mailer $mailer): void
                {
                    $token = $resets->issue($this->email);

                    if ($token !== null) {
                        $mailer->send(new Email()->to($this->email)->subject('Reset your password')
                            ->text('Choose a new password: https://app.example.com/reset-password?token=' . $token));
                    }
                }
            }
            PHP);
        file_put_contents($root . '/app/Controllers/RecoveryController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use App\Jobs\SendPasswordResetLink;
            use Psr\Http\Message\ResponseInterface;
            use Psr\Http\Message\ServerRequestInterface;
            use Trunk\Auth\Exception\InvalidPasswordException;
            use Trunk\Auth\Password\PasswordReset;
            use Trunk\Http\Response\ResponseBuilder;
            use Trunk\Queue\Queue;

            final readonly class RecoveryController
            {
                public function __construct(private Queue $queue, private PasswordReset $resets, private ResponseBuilder $responses) {}

                public function request(ServerRequestInterface $request): ResponseInterface
                {
                    $body = (array) $request->getParsedBody();
                    $email = \is_string($body['email'] ?? null) ? $body['email'] : '';
                    $this->queue->dispatch(new SendPasswordResetLink($email));

                    return $this->responses->json(['message' => 'If that address has an account, a link is on its way.']);
                }

                public function reset(ServerRequestInterface $request): ResponseInterface
                {
                    $body = (array) $request->getParsedBody();
                    $token = \is_string($body['token'] ?? null) ? $body['token'] : '';
                    $password = \is_string($body['password'] ?? null) ? $body['password'] : '';

                    try {
                        $user = $this->resets->reset($token, $password);
                    } catch (InvalidPasswordException $e) {
                        return $this->responses->json(['error' => $e->getMessage()], 422);
                    }

                    return $user === null ? $this->responses->json(['error' => 'This link no longer works. Ask for a new one.'], 400) : $this->responses->json(['message' => 'Your password is changed.']);
                }
            }
            PHP);
        file_put_contents($root . '/routes/api.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use App\Controllers\RecoveryController;
            use Trunk\Router\Definition\RouteCollector;

            return static function (RouteCollector $routes): void {
                $routes->post('/forgot-password', [RecoveryController::class, 'request']);
                $routes->post('/reset-password', [RecoveryController::class, 'reset']);
            };
            PHP);
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Trunk\Auth\Exception\InvalidPasswordException;
use Trunk\Auth\Link\OneTimeLinks;
use Trunk\Auth\Password\NativePasswordHasher;
use Trunk\Auth\Password\PasswordReset;
use Trunk\Auth\Settings\LinkSettings;
use Trunk\Auth\Settings\PasswordSettings;
use Trunk\Auth\Settings\UserSettings;
use Trunk\Auth\User\DatabaseUserProvider;
use Trunk\Auth\Verification\EmailVerification;
use Trunk\Tests\Support\AuthApp;

/**
 * Password reset and email verification over real tables: one-time links stored only as hashes,
 * bound to purpose, session version and (for verification) address; used once; ended by time.
 */
final class PasswordResetAndVerificationTest extends TestCase
{
    private const string PASSWORD = 'a brand new passphrase';

    private ?AuthApp $app = null;

    protected function tearDown(): void
    {
        $this->app?->cleanUp();
    }

    public function test_a_reset_sets_the_new_password_signs_out_everywhere_and_works_once(): void
    {
        // Arrange: a signed-in-elsewhere user with an API token
        [$app, $resets, $users, $hasher] = $this->services();
        $id = $app->createUser('ada@example.com');
        $bearer = ['Authorization' => 'Bearer ' . $app->tokens()->issue($app->user($id), 'phone')->plainText];
        $before = $app->client('development')->get('/api/whoami', $bearer)->getStatusCode();
        $token = $resets->issue('ada@example.com');
        self::assertIsString($token);

        // Act
        $user = $resets->reset($token, self::PASSWORD);
        $again = $resets->reset($token, 'yet another passphrase');

        // Assert
        self::assertNotNull($user);
        self::assertTrue($hasher->verify(self::PASSWORD, $user->authPasswordHash()));
        self::assertNotSame('1', $user->authSessionVersion(), 'the session version changed, ending every session');
        self::assertSame($user->authSessionVersion(), $users->byId($id)?->authSessionVersion(), 'what reset() returns is what is stored, so Auth::login() works with it');
        self::assertSame([200, 401], [$before, $app->client('development')->get('/api/whoami', $bearer)->getStatusCode()], 'an API token issued before the reset stops working');
        self::assertNull($again, 'a link works once');
    }

    public function test_only_a_hash_of_the_link_is_stored(): void
    {
        // Arrange
        [$app, $resets] = $this->services();
        $app->createUser('ada@example.com');

        // Act
        $token = (string) $resets->issue('ada@example.com');
        [$id, $secret] = explode('.', $token);
        $row = $app->connection->table('trunk_auth_links')->where('id', $id)->first();

        // Assert
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{43}$/D', $token);
        self::assertSame(hash('sha256', $secret), $row['token_hash'] ?? null);
        self::assertStringNotContainsString($secret, (string) json_encode($row));
    }

    public function test_an_unknown_account_gets_no_link_and_the_same_answer_as_a_known_one_from_reset(): void
    {
        // Arrange
        [$app, $resets] = $this->services();
        $app->createUser('ada@example.com');

        // Act & Assert
        self::assertNull($resets->issue('nobody@example.com'));
        self::assertSame(0, $app->connection->table('trunk_auth_links')->count());

        foreach (['', 'garbage', 'AAAAAAAAAAAAAAAA.' . str_repeat('A', 43), "x\0y", str_repeat('A', 100_000)] as $bad) {
            self::assertNull($resets->reset($bad, self::PASSWORD));
            self::assertFalse($resets->isValid($bad));
        }
    }

    public function test_a_password_the_rules_refuse_does_not_use_the_link_up(): void
    {
        // Arrange
        [$app, $resets] = $this->services();
        $app->createUser('ada@example.com');
        $token = (string) $resets->issue('ada@example.com');

        // Act
        try {
            $resets->reset($token, 'short');
            self::fail('Expected InvalidPasswordException.');
        } catch (InvalidPasswordException) {
            // Assert
            self::assertTrue($resets->isValid($token), 'the user can fix the password and submit again');
        }
    }

    public function test_a_link_expires_and_dies_when_the_password_changes_first(): void
    {
        // Arrange
        [$app, $resets] = $this->services();
        $app->createUser('ada@example.com');
        $app->createUser('bob@example.com');
        $expiring = (string) $resets->issue('ada@example.com');
        $raced = (string) $resets->issue('bob@example.com');

        // Act: one hour passes for ada; bob's password changes by another route meanwhile
        $app->clock->advance(3601);
        $app->connection->table('users')->where('email', 'bob@example.com')->update(['session_version' => 'changed']);

        // Assert
        self::assertFalse($resets->isValid($expiring));
        self::assertNull($resets->reset($expiring, self::PASSWORD));
        self::assertNull($resets->reset($raced, self::PASSWORD), 'a link is bound to the session version it was issued under');
    }

    public function test_links_cannot_flood_an_inbox_and_a_new_one_replaces_the_old(): void
    {
        // Arrange
        [$app, $resets] = $this->services();
        $app->createUser('ada@example.com');
        $first = (string) $resets->issue('ada@example.com');

        // Act
        $tooSoon = $resets->issue('ada@example.com');
        $app->clock->advance(61);
        $second = (string) $resets->issue('ada@example.com');

        // Assert
        self::assertNull($tooSoon, 'not within resend_after of the last one');
        self::assertFalse($resets->isValid($first), 'only the newest link works');
        self::assertTrue($resets->isValid($second));
    }

    public function test_a_link_for_one_purpose_does_nothing_for_the_other(): void
    {
        // Arrange
        [$app, $resets, , , $verification] = $this->services();
        $id = $app->createUser('ada@example.com');
        $verifyToken = (string) $verification->issue($app->user($id), 'ada@example.com');
        $resetToken = (string) $resets->issue('ada@example.com');

        // Act & Assert
        self::assertNull($resets->reset($verifyToken, self::PASSWORD));
        self::assertNull($verification->verify($resetToken));
        self::assertNotNull($verification->verify($verifyToken), 'each still works for its own purpose');
    }

    public function test_verification_records_the_address_once_and_only_while_it_is_the_users(): void
    {
        // Arrange
        [$app, , $users, , $verification] = $this->services();
        $ada = $app->createUser('ada@example.com');
        $bob = $app->createUser('bob@example.com');
        $adaLink = (string) $verification->issue($app->user($ada), 'ada@example.com');
        $bobLink = (string) $verification->issue($app->user($bob), 'bob@example.com');

        // Act: bob changes his address before clicking the old link
        $app->connection->table('users')->where('id', $bob)->update(['email' => 'mallory@example.com']);
        $verifiedAda = $verification->verify($adaLink);
        $verifiedBob = $verification->verify($bobLink);

        // Assert
        self::assertNotNull($verifiedAda);
        self::assertTrue($verification->isVerified($users->byId($ada) ?? $verifiedAda));
        self::assertNull($verification->verify($adaLink), 'a link works once');
        self::assertNull($verifiedBob, 'the link was for an address the account no longer has');
        self::assertFalse($verification->isVerified($app->user($bob)));
    }

    public function test_require_verified_email_sends_browsers_to_verify_and_tells_api_clients_with_a_code(): void
    {
        // Arrange
        $app = $this->app = new AuthApp();
        $id = $app->createUser('ada@example.com');
        $browser = $app->client('development');
        $browser->post('/web/login', ['email' => 'ada@example.com', 'password' => 'correct horse battery', '_csrf' => $browser->csrf()]);
        $bearer = ['Authorization' => 'Bearer ' . $app->tokens()->issue($app->user($id), 'cli')->plainText, 'Accept' => 'application/json'];

        // Act
        $page = $browser->get('/web/verified', ['Accept' => 'text/html']);
        $api = $app->client('development')->get('/api/verified', $bearer);
        $app->connection->table('users')->where('id', $id)->update(['email_verified_at' => $app->clock->now()]);
        $pageAfter = $browser->get('/web/verified', ['Accept' => 'text/html']);
        $apiAfter = $app->client('development')->get('/api/verified', $bearer);

        // Assert
        self::assertSame(302, $page->getStatusCode());
        self::assertSame('/verify-email', $page->getHeaderLine('Location'));
        self::assertSame(403, $api->getStatusCode());
        self::assertStringContainsString('"code":"EMAIL_NOT_VERIFIED"', (string) $api->getBody());
        self::assertSame(200, $pageAfter->getStatusCode());
        self::assertSame(200, $apiAfter->getStatusCode());
    }

    /**
     * @return array{AuthApp, PasswordReset, DatabaseUserProvider, NativePasswordHasher, EmailVerification}
     */
    private function services(): array
    {
        $app = $this->app = new AuthApp();
        $users = new DatabaseUserProvider($app->connection, new UserSettings());
        $links = new OneTimeLinks($app->connection, new LinkSettings(), $users, $app->clock);
        $hasher = new NativePasswordHasher(new PasswordSettings(memoryCost: 8192, timeCost: 1));

        return [$app, new PasswordReset($links, $users, $hasher, new LinkSettings()), $users, $hasher, new EmailVerification($links, $users, new LinkSettings(), $app->clock)];
    }
}

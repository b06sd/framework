<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\ApiTokens\ApiTokensModule;
use Trunk\Mvc\MvcModule;
use Trunk\Support\Directory;
use Trunk\Tests\Support\AuthApp;
use Trunk\Tests\Support\AuthClient;
use Trunk\Tusk\TuskModule;

/**
 * The API token page (/account/tokens) in a complete web application: the published template, real
 * sessions, CSRF and tokens that then work on the API.
 */
final class TokenPageTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery';

    /** @var list<AuthApp> */
    private array $apps = [];

    private string $views;

    protected function setUp(): void
    {
        $this->views = sys_get_temp_dir() . '/trunk-token-page-' . bin2hex(random_bytes(4));
        mkdir($this->views . '/layouts', 0o755, true);
        mkdir($this->views . '/account', 0o755, true);
        file_put_contents($this->views . '/layouts/app.tusk.php', "<!doctype html><title><slot name=\"title\">App</slot></title><main><slot /></main>\n");
        copy(\dirname(__DIR__, 3) . '/packages/api-tokens/resources/views/account/tokens.tusk.php', $this->views . '/account/tokens.tusk.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->apps as $app) {
            $app->cleanUp();
        }

        new Directory()->remove($this->views);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'development' => ['development'];
        yield 'compiled' => ['compiled'];
    }

    #[DataProvider('modes')]
    public function test_a_token_created_on_the_page_is_shown_once_works_on_the_api_and_can_be_revoked(string $mode): void
    {
        // Arrange
        [$app, $client] = $this->signedIn($mode);

        // Act
        $empty = $client->get('/account/tokens');
        $created = $client->post('/account/tokens', ['name' => 'CI <deploy>', 'days' => '30', 'password' => self::PASSWORD, 'form' => $this->formId($client), '_csrf' => $client->csrf()]);
        preg_match('/trk_[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{43}/', (string) $created->getBody(), $token);
        $api = $client->get('/api/whoami', ['Authorization' => 'Bearer ' . ($token[0] ?? '')]);
        $listed = (string) $client->get('/account/tokens')->getBody();
        $id = substr($token[0] ?? '', 4, 16);
        $revoked = $client->post('/account/tokens/' . $id . '/revoke', ['_csrf' => $client->csrf()]);
        $afterRevoke = $client->get('/api/whoami', ['Authorization' => 'Bearer ' . ($token[0] ?? '')]);

        // Assert
        self::assertSame(200, $empty->getStatusCode());
        self::assertStringContainsString('No tokens yet.', (string) $empty->getBody());
        self::assertSame('no-store', $empty->getHeaderLine('Cache-Control'), 'a page with tokens is never cached');
        self::assertSame(200, $created->getStatusCode());
        self::assertNotEmpty($token, 'the new token is on the page');
        self::assertStringContainsString('CI &lt;deploy&gt;', (string) $created->getBody(), 'the name is escaped');
        self::assertSame(200, $api->getStatusCode());
        self::assertStringContainsString('CI &lt;deploy&gt;', $listed);
        self::assertStringNotContainsString($token[0] ?? '-', $listed, 'the secret is never shown again');
        self::assertSame(303, $revoked->getStatusCode());
        self::assertSame('/account/tokens?done=revoked', $revoked->getHeaderLine('Location'));
        self::assertSame(401, $afterRevoke->getStatusCode());
        self::assertStringContainsString('Revoked', (string) $client->get('/account/tokens')->getBody());
    }

    public function test_creating_a_token_needs_the_password_again(): void
    {
        // Arrange
        [$app, $client] = $this->signedIn('development');

        // Act
        $response = $client->post('/account/tokens', ['name' => 'CI', 'days' => '30', 'password' => 'not my password', 'form' => $this->formId($client), '_csrf' => $client->csrf()]);

        // Assert
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('That is not your password.', (string) $response->getBody());
        self::assertStringContainsString('value="CI"', (string) $response->getBody(), 'the name is kept');
        self::assertSame([], $app->tokens()->forUser($app->user('1')));
    }

    public function test_a_form_sent_twice_makes_one_token(): void
    {
        // Arrange: what the browser's reload button does after "Create token"
        [$app, $client] = $this->signedIn('development');
        $form = ['name' => 'CI', 'days' => '30', 'password' => self::PASSWORD, 'form' => $this->formId($client), '_csrf' => $client->csrf()];

        // Act
        $first = $client->post('/account/tokens', $form);
        $again = $client->post('/account/tokens', $form);

        // Assert
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(303, $again->getStatusCode());
        self::assertSame('/account/tokens?done=stale', $again->getHeaderLine('Location'));
        self::assertCount(1, $app->tokens()->forUser($app->user('1')));
    }

    public function test_guessing_the_password_on_the_page_is_throttled_like_a_login(): void
    {
        // Arrange
        [, $client] = $this->signedIn('development');
        $statuses = [];

        // Act
        for ($i = 0; $i < 6; ++$i) {
            $statuses[] = $client->post('/account/tokens', ['name' => 'CI', 'days' => '30', 'password' => 'guess ' . $i, 'form' => $this->formId($client), '_csrf' => $client->csrf()])->getStatusCode();
        }

        $right = $client->post('/account/tokens', ['name' => 'CI', 'days' => '30', 'password' => self::PASSWORD, 'form' => $this->formId($client), '_csrf' => $client->csrf()]);

        // Assert
        self::assertSame([422, 422, 422, 422, 422, 429], $statuses);
        self::assertSame(429, $right->getStatusCode(), 'even the right password waits out the lockout');
    }

    public function test_offered_abilities_limit_what_a_token_may_do(): void
    {
        // Arrange
        [$app, $client] = $this->signedIn('development', ['abilities' => ['read', 'write'], 'max_active' => 20]);

        // Act
        $refused = $client->post('/account/tokens', ['name' => 'x', 'days' => '30', 'abilities' => ['read', 'admin'], 'password' => self::PASSWORD, 'form' => $this->formId($client), '_csrf' => $client->csrf()]);
        $created = $client->post('/account/tokens', ['name' => 'Reports', 'days' => '7', 'abilities' => ['read'], 'password' => self::PASSWORD, 'form' => $this->formId($client), '_csrf' => $client->csrf()]);
        preg_match('/trk_[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{43}/', (string) $created->getBody(), $token);
        $api = $client->json($client->get('/api/whoami', ['Authorization' => 'Bearer ' . ($token[0] ?? '')]));

        // Assert
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Choose what the token may do.', (string) $refused->getBody());
        self::assertSame(['canRead' => true, 'canWrite' => false], ['canRead' => $api['canRead'] ?? null, 'canWrite' => $api['canWrite'] ?? null]);
    }

    public function test_someone_elses_token_cannot_be_revoked_and_answers_like_one_that_does_not_exist(): void
    {
        // Arrange
        [$app, $client] = $this->signedIn('development');
        $other = $app->createUser('eve@example.com');
        $theirs = $app->tokens()->issue($app->user($other), 'theirs');

        // Act
        $response = $client->post('/account/tokens/' . $theirs->token->id . '/revoke', ['_csrf' => $client->csrf()]);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        self::assertNull($app->tokens()->forUser($app->user($other))[0]->revokedAt);
    }

    public function test_the_page_needs_a_signed_in_user_and_its_forms_need_the_csrf_token(): void
    {
        // Arrange
        [, $signedIn] = $this->signedIn('development');
        $app = $this->app();
        $visitor = $app->client('development');

        // Act
        $anonymous = $visitor->get('/account/tokens');
        $forged = $signedIn->post('/account/tokens', ['name' => 'CI', 'days' => '30', 'password' => self::PASSWORD, 'form' => $this->formId($signedIn)]);

        // Assert
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertSame(403, $forged->getStatusCode());
    }

    public function test_a_user_has_at_most_the_configured_number_of_active_tokens(): void
    {
        // Arrange
        [$app, $client] = $this->signedIn('development', ['abilities' => [], 'max_active' => 1]);
        $app->tokens()->issue($app->user('1'), 'first');

        // Act
        $response = $client->post('/account/tokens', ['name' => 'second', 'days' => '30', 'password' => self::PASSWORD, 'form' => $this->formId($client), '_csrf' => $client->csrf()]);

        // Assert
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('You already have 1 active tokens.', (string) $response->getBody());
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array{AuthApp, AuthClient}
     */
    private function signedIn(string $mode, array $settings = ['abilities' => [], 'max_active' => 20]): array
    {
        $app = $this->app($settings);
        $app->createUser('ada@example.com', self::PASSWORD);
        $client = $app->client($mode);
        $login = $client->post('/web/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD, '_csrf' => $client->csrf()]);
        self::assertSame(200, $login->getStatusCode());

        return [$app, $client];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function app(array $settings = ['abilities' => [], 'max_active' => 20]): AuthApp
    {
        return $this->apps[] = new AuthApp(extraModules: [TuskModule::class, MvcModule::class, ApiTokensModule::class], configuration: [
            'views' => ['mode' => 'development', 'paths' => [$this->views], 'cache' => $this->views . '/.cache', 'build' => $this->views . '/.build'],
            'api_tokens' => $settings,
        ]);
    }

    private function formId(AuthClient $client): string
    {
        preg_match('/name="form" value="([0-9a-f]{32})"/', (string) $client->get('/account/tokens')->getBody(), $m);

        return $m[1] ?? '';
    }
}

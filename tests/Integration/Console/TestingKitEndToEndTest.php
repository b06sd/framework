<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Testing\TestApp;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * Proves `trunkphp/testing` against a real, scaffolded application: the exact "coming from Laravel"
 * flow docs/ELEGANCE_REVIEW.md found painful (no test client, no way to read a CSRF token, hand-rolled
 * kernel wiring for every feature test) now takes a few lines, with real cookies and a real login.
 *
 * Each test runs in its own process: a scaffolded project's classes all live under the same `App\`
 * namespace as any other scaffolded project, so PHP cannot load two different ones in one process
 * (this is a fixture-collision artefact of this test suite scaffolding more than one throwaway
 * project; a real application only ever has its own single App\ namespace).
 */
final class TestingKitEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    #[RunInSeparateProcess]
    public function test_a_plain_api_route_works_with_no_setup_beyond_the_project_root(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        require_once $project->directory . '/vendor/autoload.php';

        // Act
        $response = TestApp::client($project->directory)->get('/api/customers/2');

        // Assert
        $response->assertOk()->assertJson(['name' => 'Grace Hopper']);
    }

    #[RunInSeparateProcess]
    public function test_a_real_login_with_csrf_and_a_protected_page_works_through_cookies_alone(): void
    {
        // Arrange: exactly the routes, controller and view from docs/auth.md.
        $this->project = $project = new ScaffoldedProject('portal', 'web');
        $project->trunk(['package:install', 'database']);
        $project->trunk(['package:install', 'auth']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['auth:table', '--users']);
        $project->trunk(['migrate']);
        $this->seedUser($project->directory, 'ada@example.com', 'correct horse battery');
        $this->writeAccountController($project->directory);
        $this->writeLoginView($project->directory);
        $this->addRoutes($project->directory);
        require_once $project->directory . '/vendor/autoload.php';

        // Act: log in with a real CSRF token read from the real login page, then visit a protected page.
        $client = TestApp::client($project->directory);
        $csrf = $client->get('/login')->csrfToken();
        $login = $client->post('/login', ['_csrf' => $csrf, 'email' => 'ada@example.com', 'password' => 'correct horse battery']);
        $account = $client->get('/account');

        // Also: a fresh client (no cookies at all) must be turned away. TestClient asks for JSON
        // (Accept: application/json), and RequireLogin's documented behaviour for that is a plain 401,
        // not the redirect-to-/login it gives an actual browser.
        $anonymous = TestApp::client($project->directory)->get('/account');

        // Assert
        $login->assertRedirect('/account');
        $account->assertOk()->assertSee('ada@example.com');
        $anonymous->assertStatus(401);
    }

    private function seedUser(string $root, string $email, string $password): void
    {
        $connection = new ConnectionFactory()->make('seed', ['driver' => 'sqlite', 'database' => $root . '/storage/database.sqlite']);
        $connection->table('users')->insert(['name' => 'Ada', 'email' => $email, 'password' => (string) password_hash($password, \PASSWORD_ARGON2ID), 'session_version' => '1']);
    }

    private function writeAccountController(string $root): void
    {
        file_put_contents($root . '/app/Controllers/AccountController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Psr\Http\Message\ResponseInterface;
            use Psr\Http\Message\ServerRequestInterface;
            use Trunk\Auth\Auth;
            use Trunk\Auth\Csrf\Csrf;
            use Trunk\Mvc\Responder;

            final readonly class AccountController
            {
                public function __construct(private Responder $responder, private Auth $auth, private Csrf $csrf) {}

                public function loginForm(): ResponseInterface
                {
                    return $this->responder->view('account/login', ['csrf' => $this->csrf->token(), 'error' => null]);
                }

                public function login(ServerRequestInterface $request): ResponseInterface
                {
                    $body = (array) $request->getParsedBody();
                    $user = $this->auth->attempt((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));

                    if ($user === null) {
                        return $this->responder->view('account/login', ['csrf' => $this->csrf->token(), 'error' => 'Wrong email or password.'], 401);
                    }

                    return $this->responder->redirect($this->auth->intended('/account'));
                }

                public function show(): ResponseInterface
                {
                    return $this->responder->view('account/show', ['user' => $this->auth->user(), 'csrf' => $this->csrf->token()]);
                }
            }

            PHP);
        is_dir($root . '/resources/views/account') || mkdir($root . '/resources/views/account', 0o755, true);
        file_put_contents($root . '/resources/views/account/show.tusk.php', "<layout name=\"app\">\n    <fill slot=\"title\">Account</fill>\n    <p>{{ user.attributes.email }}</p>\n</layout>\n");
    }

    private function writeLoginView(string $root): void
    {
        is_dir($root . '/resources/views/account') || mkdir($root . '/resources/views/account', 0o755, true);
        file_put_contents($root . '/resources/views/account/login.tusk.php', <<<'HTML'
            <layout name="app">
                <fill slot="title">Sign in</fill>
                <if test="error"><p role="alert">{{ error }}</p></if>
                <form method="post" action="/login">
                    <input type="hidden" name="_csrf" value="{{ csrf }}">
                    <label>Email <input name="email" type="email"></label>
                    <label>Password <input name="password" type="password"></label>
                    <button type="submit">Sign in</button>
                </form>
            </layout>
            HTML);
    }

    private function addRoutes(string $root): void
    {
        file_put_contents($root . '/routes/web.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use App\Controllers\AccountController;
            use Trunk\Auth\Http\CsrfMiddleware;
            use Trunk\Auth\Http\RequireLogin;
            use Trunk\Auth\Http\SessionMiddleware;
            use Trunk\Router\Definition\RouteCollector;

            return static function (RouteCollector $routes): void {
                $web = [SessionMiddleware::class, CsrfMiddleware::class];
                $routes->get('/login', [AccountController::class, 'loginForm'], middleware: $web);
                $routes->post('/login', [AccountController::class, 'login'], middleware: $web);
                $routes->get('/account', [AccountController::class, 'show'], middleware: [...$web, RequireLogin::class]);
            };
            PHP);
    }
}

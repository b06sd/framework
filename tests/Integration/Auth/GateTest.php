<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Trunk\Auth\Auth;
use Trunk\Auth\AuthModule;
use Trunk\Auth\Authorization\Ability;
use Trunk\Auth\Authorization\Gate;
use Trunk\Auth\Authorization\Policy;
use Trunk\Auth\Session\Session;
use Trunk\Auth\Throttle\AttemptCounter;
use Trunk\Auth\Throttle\LoginThrottle;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Fixtures\Auth\BadPolicyModule;
use Trunk\Tests\Fixtures\Auth\DraftPost;
use Trunk\Tests\Fixtures\Auth\ManageUsers;
use Trunk\Tests\Fixtures\Auth\PolicyModule;
use Trunk\Tests\Fixtures\Auth\Post;
use Trunk\Tests\Fixtures\Auth\PostPolicy;
use Trunk\Tests\Support\AuthApp;
use Trunk\Tests\Support\AuthHarness;

final class GateTest extends TestCase
{
    private ?AuthHarness $harness = null;

    private ?AuthApp $app = null;

    protected function tearDown(): void
    {
        $this->harness?->cleanUp();
        $this->app?->cleanUp();
    }

    public function test_nobody_signed_in_means_everything_is_denied_and_authorize_is_401(): void
    {
        // Arrange
        $gate = $this->gate(null);

        // Act & Assert
        self::assertFalse($gate->allows('view', new Post('1', '1')));
        self::assertFalse($gate->allows('manage-users'));
        self::assertTrue($gate->denies('manage-users'));

        try {
            $gate->authorize('view', new Post('1', '1'));
            self::fail('expected a 401');
        } catch (HttpException $e) {
            self::assertSame(401, $e->statusCode());
        }
    }

    public function test_policies_decide_per_ability_and_everything_unknown_is_denied(): void
    {
        // Arrange
        $harness = $this->harness = AuthHarness::for('sqlite') ?? self::fail();
        $owner = $harness->createUser('owner@example.com');
        $gate = $this->gate($owner);
        $mine = new Post('1', $owner->authId());
        $theirs = new Post('2', 'someone-else');

        // Act & Assert
        self::assertTrue($gate->allows('view', $theirs));
        self::assertTrue($gate->allows('update', $mine));
        self::assertFalse($gate->allows('update', $theirs));
        self::assertFalse($gate->allows('unheard-of', $mine), 'an ability the policy does not know is denied');
        self::assertFalse($gate->allows('update', new stdClass()), 'an object no policy handles is denied');
        self::assertFalse($gate->allows('update'), 'an object ability needs its object');
        self::assertFalse($gate->allows('nobody-defined-this'), 'an unregistered general ability is denied');
    }

    public function test_a_subclass_uses_the_policy_of_its_parent_class(): void
    {
        // Arrange
        $harness = $this->harness = AuthHarness::for('sqlite') ?? self::fail();
        $owner = $harness->createUser('owner@example.com');
        $gate = $this->gate($owner);

        // Act & Assert
        self::assertTrue($gate->allows('update', new DraftPost('9', $owner->authId())));
        self::assertFalse($gate->allows('update', new DraftPost('9', 'other')));
    }

    public function test_general_abilities_and_authorize_return_403_with_the_access_denied_code(): void
    {
        // Arrange
        $harness = $this->harness = AuthHarness::for('sqlite') ?? self::fail();
        $admin = $harness->createUser('admin@example.com');
        $user = $harness->createUser('user@example.com');

        // Act & Assert
        self::assertTrue($this->gate($admin)->allows('manage-users'));
        self::assertFalse($this->gate($user)->allows('manage-users'));

        try {
            $this->gate($user)->authorize('manage-users');
            self::fail('expected a 403');
        } catch (HttpException $e) {
            self::assertSame([403, 'ACCESS_DENIED'], [$e->statusCode(), $e->errorCode()]);
        }

        $this->gate($admin)->authorize('manage-users');
    }

    public function test_an_error_inside_a_policy_is_never_treated_as_allow_or_deny(): void
    {
        // Arrange
        $harness = $this->harness = AuthHarness::for('sqlite') ?? self::fail();
        $gate = $this->gate($harness->createUser('owner@example.com'));

        // Act & Assert
        $this->expectException(RuntimeException::class);
        $gate->allows('explode', new Post('1', '1'));
    }

    public function test_a_token_may_only_do_what_it_lists_even_when_the_policy_would_allow_it(): void
    {
        // Arrange
        $harness = $this->harness = AuthHarness::for('sqlite') ?? self::fail();
        $owner = $harness->createUser('owner@example.com');
        $mine = new Post('1', $owner->authId());

        // Act & Assert
        self::assertTrue($this->gate($owner, ['view'])->allows('view', $mine));
        self::assertFalse($this->gate($owner, ['view'])->allows('update', $mine));
        self::assertTrue($this->gate($owner, ['*'])->allows('update', $mine));
        self::assertTrue($this->gate($owner, ['update'])->allows('update', $mine));
    }

    public function test_a_tagged_service_of_the_wrong_kind_or_a_duplicate_ability_name_is_refused(): void
    {
        // Arrange
        $auth = $this->auth(null);

        // Act & Assert
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/tagged "auth.policy" must implement/');
        new Gate($auth, [new stdClass()]);
        $this->expectException(LogicException::class);
        new Gate($auth, [], [new ManageUsers(), new ManageUsers()]);
    }

    public function test_the_build_refuses_tagged_classes_that_are_not_policies_or_abilities(): void
    {
        // Arrange
        $manifest = new ModuleManifest([BadPolicyModule::class]);
        $context = new BuildContext($manifest, new Runtime(Environment::Production, false, sys_get_temp_dir()), new Configuration([]));

        // Act & Assert
        try {
            new AuthModule()->plan($context);
            self::fail('expected the build to fail');
        } catch (CompilationException $e) {
            self::assertStringContainsString('stdClass is tagged "auth.policy" but does not implement ' . Policy::class, $e->getMessage());
            self::assertStringContainsString('stdClass is tagged "auth.ability" but does not implement ' . Ability::class, $e->getMessage());
        }
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
    public function test_policies_registered_by_tag_work_through_the_kernel(string $mode): void
    {
        // Arrange
        $app = $this->app = new AuthApp(extraModules: [PolicyModule::class]);
        $ownerId = $app->createUser('owner@example.com');
        $app->createUser('admin@example.com');
        $owner = $app->client($mode);
        $owner->post('/web/login', ['email' => 'owner@example.com', 'password' => 'correct horse battery', '_csrf' => $owner->csrf()]);
        $admin = $app->client($mode);
        $admin->post('/web/login', ['email' => 'admin@example.com', 'password' => 'correct horse battery', '_csrf' => $admin->csrf()]);

        // Act
        $own = $owner->post('/web/posts/' . $ownerId . '/update', ['_csrf' => $owner->csrf()]);
        $others = $owner->post('/web/posts/999/update', ['_csrf' => $owner->csrf()]);
        $ownerAdmin = $owner->get('/web/admin');
        $adminAdmin = $admin->get('/web/admin');

        // Assert
        self::assertSame(200, $own->getStatusCode());
        self::assertSame(403, $others->getStatusCode());
        self::assertSame(403, $others->getStatusCode());
        self::assertSame(403, $ownerAdmin->getStatusCode());
        self::assertSame(200, $adminAdmin->getStatusCode());
    }

    /**
     * @param list<string>|null $tokenAbilities
     */
    private function gate(?\Trunk\Auth\User\DatabaseUser $user, ?array $tokenAbilities = null): Gate
    {
        return new Gate($this->auth($user, $tokenAbilities), [new PostPolicy()], [new ManageUsers()]);
    }

    /**
     * @param list<string>|null $tokenAbilities
     */
    private function auth(?\Trunk\Auth\User\DatabaseUser $user, ?array $tokenAbilities = null): Auth
    {
        $harness = $this->harness ??= AuthHarness::for('sqlite') ?? self::fail();
        $session = new Session();
        $session->start(null, null, $harness->clock->now());
        $auth = new Auth($session, $harness->users, $harness->hasher, new LoginThrottle(new AttemptCounter($harness->connection, $harness->settings->throttle, $harness->clock), $harness->settings->throttle), new ServerRequest('GET', 'http://app.test/'));

        if ($user !== null) {
            $tokenAbilities === null ? $auth->login($user) : $auth->authenticatedByToken($user, $tokenAbilities);
        }

        return $auth;
    }
}

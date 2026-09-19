<?php

declare(strict_types=1);

namespace Trunk\Auth;

use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use Trunk\Auth\Exception\InvalidPasswordException;
use Trunk\Auth\Password\PasswordHasher;
use Trunk\Auth\Session\Session;
use Trunk\Auth\Throttle\LoginThrottle;
use Trunk\Auth\User\Authenticatable;
use Trunk\Auth\User\UserProvider;
use Trunk\Http\Server\ServerRequestCreator;

/**
 * Who is signed in, and how to sign in and out. Injected per request (it is request-scoped, so nothing
 * carries over between requests). Routes that read the user need `SessionMiddleware` (or
 * `RequireToken`) in front of them.
 *
 * `attempt()` is the whole login step: it is throttled, takes the same time whether or not the account
 * exists, gives no hint which part was wrong, upgrades an outdated hash, and starts a fresh session.
 *
 * @api
 */
final class Auth
{
    private const string USER = 'user';

    private const string VERSION = 'version';

    private const string INTENDED = 'intended';

    private ?Authenticatable $user = null;

    private bool $resolved = false;

    /** @var list<string>|null abilities of the bearer token that authenticated this request */
    private ?array $tokenAbilities = null;

    /** @internal wired by the container */
    public function __construct(
        private readonly Session $session,
        private readonly UserProvider $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly ServerRequestInterface $request,
    ) {}

    /**
     * The signed-in user, or null. Loaded once per request; a session whose user is gone, or whose
     * session version changed since login (a password change), is ended and counts as signed out.
     */
    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $id = $this->session->internal(self::USER);

        if (!\is_string($id) || $id === '') {
            return null;
        }

        $user = $this->users->byId($id);
        $version = $this->session->internal(self::VERSION, '');

        if ($user === null || !\is_string($version) || !hash_equals($user->authSessionVersion(), $version)) {
            $this->session->invalidate();

            return null;
        }

        return $this->user = $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Checks the credentials and, on success, signs the user in. Returns null on any failure.
     *
     * @throws \Trunk\Http\Exception\HttpException 429 when there have been too many failed attempts
     */
    public function attempt(string $identifier, #[SensitiveParameter] string $password): ?Authenticatable
    {
        $address = $this->address();
        $this->throttle->assertAllowed($identifier, $address);
        $user = $this->users->byIdentifier($identifier);
        $hash = $user?->authPasswordHash() ?? '';

        if ($user === null || $hash === '') {
            // Do the same amount of hashing work as a real check, so timing does not reveal accounts.
            $this->burn();
            $this->throttle->recordFailure($identifier, $address);

            return null;
        }

        if (!$this->hasher->verify($password, $hash)) {
            $this->throttle->recordFailure($identifier, $address);

            return null;
        }

        $this->throttle->clear($identifier, $address);
        $this->upgrade($user, $password, $hash);
        $this->login($user);

        return $user;
    }

    /**
     * Signs a user in without a password (after email verification, single sign-on...). The session id
     * changes and a fresh lifetime starts, so a session an attacker planted earlier is worthless.
     */
    public function login(Authenticatable $user): void
    {
        $this->session->regenerate(freshLifetime: true);
        $this->session->forgetInternal('csrf');
        $this->session->setInternal(self::USER, $user->authId());
        $this->session->setInternal(self::VERSION, $user->authSessionVersion());
        $this->user = $user;
        $this->resolved = true;
    }

    /**
     * Ends the session completely: data gone, id dead, cookie removed.
     */
    public function logout(): void
    {
        $this->session->invalidate();
        $this->user = null;
        $this->resolved = true;
        $this->tokenAbilities = null;
    }

    /**
     * Where to send the user after signing in: the page `RequireLogin` interrupted, if it was a local
     * path, otherwise `$default`. Read once.
     */
    public function intended(string $default = '/'): string
    {
        $path = $this->session->internal(self::INTENDED);
        $this->session->forgetInternal(self::INTENDED);

        return \is_string($path) && self::isLocalPath($path) ? $path : $default;
    }

    /** @internal used by RequireLogin to remember the interrupted page */
    public function rememberIntended(string $path): void
    {
        if (self::isLocalPath($path)) {
            $this->session->setInternal(self::INTENDED, $path);
        }
    }

    /**
     * True when this request was authenticated by a bearer token that grants the ability (a token with
     * `*` grants all). Session logins are not limited by token abilities.
     */
    public function tokenCan(string $ability): bool
    {
        return $this->tokenAbilities !== null && (\in_array('*', $this->tokenAbilities, true) || \in_array($ability, $this->tokenAbilities, true));
    }

    public function viaToken(): bool
    {
        return $this->tokenAbilities !== null;
    }

    /**
     * @param list<string> $abilities
     *
     * @internal used by RequireToken
     */
    public function authenticatedByToken(Authenticatable $user, array $abilities): void
    {
        $this->user = $user;
        $this->resolved = true;
        $this->tokenAbilities = $abilities;
    }

    private function upgrade(Authenticatable $user, #[SensitiveParameter] string $password, string $hash): void
    {
        if (!$this->hasher->needsRehash($hash)) {
            return;
        }

        try {
            $this->users->updatePasswordHash($user, $this->hasher->hash($password));
        } catch (InvalidPasswordException) {
            // A password set before the current length rules cannot be re-hashed; it stays valid as it is.
        }
    }

    private function burn(): void
    {
        $this->hasher->hash(bin2hex(random_bytes(16)));
    }

    private function address(): ?string
    {
        $address = $this->request->getAttribute(ServerRequestCreator::CLIENT_IP_ATTRIBUTE);

        return \is_string($address) && $address !== '' ? $address : null;
    }

    private static function isLocalPath(string $path): bool
    {
        return \strlen($path) <= 512 && preg_match('#^/(?!/)[^\s\\\\\x00-\x1F\x7F]*$#D', $path) === 1;
    }
}

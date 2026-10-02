# Auth

`trunk package:install auth` (needs `console` for the commands), then:

```bash
trunk auth:table --users     # migration for sessions, tokens, login throttles, one-time links (+ a starter users table)
trunk migrate
```

Nothing runs on a route until you put its middleware there, so API routes and public pages pay nothing.

| Middleware | Use | Put after |
| --- | --- | --- |
| `SessionMiddleware` | Loads and saves the session (cookie) | |
| `CsrfMiddleware` | Rejects unsafe requests without a valid token | SessionMiddleware |
| `RequireLogin` | Browsers go to `auth.login_path`, everything else gets 401 | SessionMiddleware |
| `RequireToken` | Bearer-token API authentication (no cookies, no CSRF) | |
| `RequireVerifiedEmail` | Only users with a verified address; browsers go to `auth.verify_path`, API clients get 403 `EMAIL_NOT_VERIFIED` | RequireLogin or RequireToken |

Services (request-scoped, inject them): `Auth`, `Csrf`, `Session`, `Gate`. `TokenManager`, `PasswordReset` and `EmailVerification` are singletons.

## Web sign-in, end to end

A group needs a prefix; for routes at the root use per-route middleware:

```php
$web = [SessionMiddleware::class, CsrfMiddleware::class];
$routes->get('/login', [AccountController::class, 'loginForm'], middleware: $web);
$routes->post('/login', [AccountController::class, 'login'], middleware: $web);
$routes->post('/logout', [AccountController::class, 'logout'], middleware: $web);
$routes->get('/account', [AccountController::class, 'show'], middleware: [...$web, RequireLogin::class]);
```

```php
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

        if ($user === null) {                                       // wrong password and unknown user look identical
            return $this->responder->view('account/login', ['csrf' => $this->csrf->token(), 'error' => 'Wrong email or password.'], 401);
        }

        return $this->responder->redirect($this->auth->intended('/account'));   // back to the page RequireLogin interrupted
    }

    public function logout(): ResponseInterface
    {
        $this->auth->logout();

        return $this->responder->redirect('/login');
    }

    public function show(): ResponseInterface
    {
        return $this->responder->view('account/show', ['user' => $this->auth->user(), 'csrf' => $this->csrf->token()]);
    }
}
```

```html
<!-- resources/views/account/login.tusk.php -->
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
```

Every form that posts needs `_csrf`; scripts send the same value in an `X-CSRF-Token` header. Create the first user from a command (`trunk make:command CreateUser`, then `$commands->add(...)` in `AppModule::commands()`):

```php
$this->db->table('users')->insert(['name' => $name, 'email' => $email, 'password' => $this->hasher->hash($password), 'session_version' => '1']);
```

## What it does for you

* **Passwords**: argon2id via `password_hash`, upgraded automatically on login; min length 12, max 1024 bytes (long inputs are a hashing DoS). A login for an unknown user (or an account with no password) does the same amount of hashing as a real check, so timing does not reveal accounts.
* **Sessions**: server-side in the database (default), files or memory (`auth.session.store`). The cookie holds a random 256-bit id and **only its SHA-256 is stored**. The id changes at login and logout; a client-supplied id is never adopted; idle timeout (2 h) and absolute lifetime (12 h); changing a user's `session_version` signs them out everywhere (and ends their tokens). Visitors who store nothing get no cookie and no row.
* **Cookies**: `HttpOnly`, `SameSite=Lax`, host-only, `Secure` and `__Host-` prefixed in production (`auth.session.secure`). Pages shown to a signed-in user get `Cache-Control: no-store`, so the back button cannot resurrect them after logout.
* **CSRF**: a per-session secret, masked freshly in every token (the same value never repeats), compared with `hash_equals`, plus `Origin` and `Sec-Fetch-Site` checks (`same-site` from another subdomain is refused).
* **Throttling** (database counters, atomic): 5 failed logins per account+address and 30 per address per 15 minutes, then 429 with `Retry-After`; identifier case and spacing cannot be used to get fresh attempts. One address can also start only 60 anonymous sessions per window.
* **Users**: the `UserProvider` interface. The default reads `users` through the query builder (columns configurable); for the ORM implement `Authenticatable` and `UserProvider` yourself and bind it: `$builder->bind(UserProvider::class, OrmUserProvider::class)`. Identifiers containing control characters (a NUL would be cut off by some databases) never match.

## API tokens

```bash
trunk auth:token 1 ci --abilities=read,write:posts --ttl=86400     # printed once
```

```php
$routes->group('/api', static function (RouteCollector $api): void {
    $api->get('/me', [MeController::class, 'show']);
}, middleware: [RequireToken::class]);
```

```bash
curl -H "Authorization: Bearer trk_XXXXXXXXXXXXXXXX.…" localhost:8006/api/me
```

`trk_<id>.<secret>`: the database keeps only the secret's hash; unknown, expired, revoked, malformed and orphaned tokens all give the same 401 with `WWW-Authenticate: Bearer`. A token is tied to its owner's `session_version`. In the route, `$auth->user()` is the owner and `$auth->tokenCan('write:posts')` checks what the token may do. Issue from code with `TokenManager::issue($user, 'ci', ['read'], ttl: 3600)` (returns a `NewToken`; show `->plainText` once); `revoke($id)`, `revokeAllFor($user)`.

## Password reset and email verification

Both work with one-time links, sent with the [mail](mail.md) capability. A link token (`<id>.<secret>`) is stored only as a hash, works once (two simultaneous clicks cannot both succeed), expires (`auth.links.reset_ttl`, one hour; `verify_ttl`, one day), and a new link replaces the previous one. No second link goes out within `auth.links.resend_after` seconds (60), so a form cannot flood someone's inbox. A project created before these existed adds the table with `trunk auth:table --links && trunk migrate`.

**Password reset.** The request form must not reveal whether an account exists, and sending an email takes time a stranger can measure. So the form only queues a job, always, and always gives the same answer; the job does the lookup and the sending:

```php
// POST /forgot-password
public function request(ServerRequestInterface $request): ResponseInterface
{
    $body = (array) $request->getParsedBody();
    $email = \is_string($body['email'] ?? null) ? $body['email'] : '';
    $this->queue->dispatch(new SendPasswordResetLink($email));          // the same work for every address

    return $this->responses->json(['message' => 'If that address has an account, a link is on its way.']);
}

// app/Jobs/SendPasswordResetLink.php
final readonly class SendPasswordResetLink implements Job
{
    public function __construct(public string $email) {}

    public function handle(PasswordReset $resets, Mailer $mailer): void
    {
        $token = $resets->issue($this->email);   // null: no such account, or a link went out a moment ago

        if ($token !== null) {
            $mailer->send(new Email()->to($this->email)->subject('Reset your password')
                ->text('Choose a new password: https://app.example.com/reset-password?token=' . $token));
        }
    }
}

// POST /reset-password (the token from the link, sent back from your form)
$user = $this->resets->reset($token, $password);   // throws InvalidPasswordException for a password the rules refuse
```

`reset()` checks the new password before it touches the link (a refused password leaves the link working), then sets it and changes the user's session version, which ends every session and API token they had. It returns the user as now stored, ready for `$auth->login($user)`, or null for any link that is unknown, expired, used, or older than the last password change. `isValid($token)` tells you whether to show the form at all. Put `RateLimitMiddleware` on these routes to limit attempts per address.

**Email verification.** After sign-up (or on request), send a link to the address the user signs in with, and verify it when clicked:

```php
$token = $this->verification->issue($user, $email);        // null if one went out a moment ago
// ... mail https://app.example.com/verify-email?token=$token

$user = $this->verification->verify($token);               // null: unknown, expired, used, or address changed
```

The link is bound to the address it was sent to: if the account's address changed in the meantime, the old link verifies nothing. When your application changes a user's address, set `email_verified_at` (`auth.users.verified_at`) back to null so the new one is checked too. Guard routes with `RequireVerifiedEmail`, after `RequireLogin` or `RequireToken`.

## Authorization

`Gate` **denies by default**: nobody signed in, an unknown ability, an object no policy handles, or a token that does not list the ability are all refusals. An exception inside a policy fails the request; it never allows.

```php
final class PostPolicy implements Policy
{
    public function handles(): array { return [Post::class]; }

    public function can(string $ability, Authenticatable $user, object $subject): bool
    {
        return $subject instanceof Post && match ($ability) {
            'view' => true,
            'update', 'delete' => $subject->ownerId === $user->authId(),
            default => false,
        };
    }
}

final class ManageUsers implements Ability      // a permission that is not about one object
{
    public function name(): string { return 'manage-users'; }
    public function allows(Authenticatable $user): bool { return $user->authId() === '1'; }
}

// AppModule::register()
$builder->autowire(PostPolicy::class, Lifetime::Scoped);   $builder->tag('auth.policy', PostPolicy::class);
$builder->autowire(ManageUsers::class, Lifetime::Scoped);  $builder->tag('auth.ability', ManageUsers::class);

// controller
$this->gate->authorize('update', $post);      // 401 if signed out, 403 (ACCESS_DENIED) if not allowed
if ($this->gate->allows('manage-users')) { ... }
```

A class tagged `auth.policy` that does not implement `Policy` (or `auth.ability` / `Ability`) fails `trunk build`.

## Maintenance and measured cost

`trunk auth:prune` (run it from cron) removes dead sessions, expired or long-revoked tokens, finished throttle windows and expired one-time links. On the reference laptop (PHP 8.5, SQLite file): an authenticated session request about 41 µs versus 17 µs for an unauthenticated route (one session read and one user read, no writes while the session is in use); bearer-token request 38 µs; login about 150 ms, almost all argon2id at the default 64 MiB / 4 passes (19 MiB / 2 passes, the OWASP minimum, is 19 ms). Lower `auth.password.memory_cost` / `time_cost` if login concurrency matters more than the margin.

## Not built yet

Remember-me cookies, two-factor, OAuth/OIDC, WebAuthn, other session stores.

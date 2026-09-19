<?php

declare(strict_types=1);

namespace Trunk\Auth\Settings;

use InvalidArgumentException;
use Trunk\Foundation\Configuration;

/**
 * config/auth.php as typed, validated values. Built once (at build time to validate, at run time to
 * use); every range and identifier is checked so a bad setting fails the build, not a request.
 */
final readonly class AuthSettings
{
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D';

    public function __construct(
        public PasswordSettings $password = new PasswordSettings(),
        public UserSettings $users = new UserSettings(),
        public SessionSettings $session = new SessionSettings(),
        public TokenSettings $tokens = new TokenSettings(),
        public ThrottleSettings $throttle = new ThrottleSettings(),
        public string $loginPath = '/login',
    ) {
        $problems = $this->problems();

        if ($problems !== []) {
            throw new InvalidArgumentException(implode("\n", $problems));
        }
    }

    public static function fromConfiguration(Configuration $configuration): self
    {
        $d = new self();
        $string = static fn(string $key, string $default): string => $configuration->has($key) && \is_string($configuration->get($key)) ? $configuration->string($key) : $default;
        $int = static fn(string $key, int $default): int => $configuration->has($key) && \is_int($configuration->get($key)) ? $configuration->int($key) : $default;
        $bool = static fn(string $key, bool $default): bool => $configuration->has($key) && \is_bool($configuration->get($key)) ? $configuration->bool($key) : $default;

        return new self(
            new PasswordSettings($string('auth.password.algorithm', $d->password->algorithm), $int('auth.password.memory_cost', $d->password->memoryCost), $int('auth.password.time_cost', $d->password->timeCost), $int('auth.password.threads', $d->password->threads), $int('auth.password.min_length', $d->password->minLength), $int('auth.password.max_length', $d->password->maxLength)),
            new UserSettings($string('auth.users.table', $d->users->table), $string('auth.users.id', $d->users->id), $string('auth.users.identifier', $d->users->identifier), $string('auth.users.password', $d->users->password), $string('auth.users.session_version', $d->users->sessionVersion)),
            new SessionSettings($string('auth.session.store', $d->session->store), $string('auth.session.table', $d->session->table), $string('auth.session.path', $d->session->path), $string('auth.session.cookie', $d->session->cookie), $int('auth.session.idle_timeout', $d->session->idleTimeout), $int('auth.session.lifetime', $d->session->lifetime), $string('auth.session.same_site', $d->session->sameSite), $bool('auth.session.secure', $d->session->secure)),
            new TokenSettings($string('auth.tokens.table', $d->tokens->table), $int('auth.tokens.ttl', $d->tokens->ttl), $int('auth.tokens.touch_interval', $d->tokens->touchInterval)),
            new ThrottleSettings($string('auth.throttle.table', $d->throttle->table), $int('auth.throttle.max_attempts', $d->throttle->maxAttempts), $int('auth.throttle.max_attempts_per_ip', $d->throttle->maxAttemptsPerIp), $int('auth.throttle.window', $d->throttle->window), $int('auth.throttle.max_new_sessions_per_ip', $d->throttle->maxNewSessionsPerIp)),
            $string('auth.login_path', $d->loginPath),
        );
    }

    /**
     * @return list<string>
     */
    private function problems(): array
    {
        $p = [];
        $name = static fn(string $value): bool => preg_match(self::NAME, $value) === 1;

        foreach (['auth.users.table' => $this->users->table, 'auth.users.id' => $this->users->id, 'auth.users.identifier' => $this->users->identifier, 'auth.users.password' => $this->users->password, 'auth.users.session_version' => $this->users->sessionVersion, 'auth.session.table' => $this->session->table, 'auth.tokens.table' => $this->tokens->table, 'auth.throttle.table' => $this->throttle->table] as $key => $value) {
            if (!$name($value)) {
                $p[] = $key . ' must be a plain table or column name (letters, digits, underscore).';
            }
        }

        if (!\in_array($this->password->algorithm, ['argon2id', 'bcrypt'], true)) {
            $p[] = 'auth.password.algorithm must be argon2id or bcrypt.';
        } elseif ($this->password->algorithm === 'argon2id' && !\defined('PASSWORD_ARGON2ID')) {
            $p[] = 'auth.password.algorithm is argon2id but this PHP was built without it. Use bcrypt, or install PHP with argon2 support.';
        }

        if ($this->password->memoryCost < 8192 || $this->password->memoryCost > 4_194_304 || $this->password->timeCost < 1 || $this->password->timeCost > 20 || $this->password->threads < 1 || $this->password->threads > 16) {
            $p[] = 'auth.password memory_cost (8192 to 4194304 KiB), time_cost (1 to 20) and threads (1 to 16) are out of range.';
        }

        if ($this->password->minLength < 8 || $this->password->minLength > $this->password->maxLength || $this->password->maxLength > 4096) {
            $p[] = 'auth.password.min_length must be at least 8 and no more than max_length (at most 4096).';
        }

        if (!\in_array($this->session->store, ['database', 'file', 'array'], true)) {
            $p[] = 'auth.session.store must be database, file or array.';
        }

        if ($this->session->store === 'file' && $this->session->path === '') {
            $p[] = 'auth.session.path is required for the file session store.';
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $this->session->cookie) !== 1) {
            $p[] = 'auth.session.cookie must be lowercase letters, digits or underscore (max 32).';
        }

        if ($this->session->idleTimeout < 60 || $this->session->lifetime < $this->session->idleTimeout || $this->session->lifetime > 31_536_000) {
            $p[] = 'auth.session idle_timeout must be at least 60 seconds and no more than lifetime (at most one year).';
        }

        if (!\in_array($this->session->sameSite, ['Lax', 'Strict', 'None'], true)) {
            $p[] = 'auth.session.same_site must be Lax, Strict or None.';
        } elseif ($this->session->sameSite === 'None' && !$this->session->secure) {
            $p[] = 'auth.session.same_site None requires auth.session.secure = true.';
        }

        if ($this->tokens->ttl < 0 || $this->tokens->ttl > 315_360_000 || $this->tokens->touchInterval < 0) {
            $p[] = 'auth.tokens ttl (0 or up to ten years) and touch_interval (0 or more) are out of range.';
        }

        if ($this->throttle->maxNewSessionsPerIp < 1 || $this->throttle->maxNewSessionsPerIp > 100_000) {
            $p[] = 'auth.throttle.max_new_sessions_per_ip must be between 1 and 100000.';
        }

        if ($this->throttle->maxAttempts < 1 || $this->throttle->maxAttemptsPerIp < $this->throttle->maxAttempts || $this->throttle->window < 1 || $this->throttle->window > 86_400) {
            $p[] = 'auth.throttle: max_attempts must be at least 1, max_attempts_per_ip at least max_attempts, window between 1 and 86400 seconds.';
        }

        if (preg_match('#^/(?!/)[^\s\\\\]*$#D', $this->loginPath) !== 1) {
            $p[] = 'auth.login_path must be a local path starting with a single "/".';
        }

        return $p;
    }
}

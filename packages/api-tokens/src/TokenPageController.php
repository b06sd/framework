<?php

declare(strict_types=1);

namespace Trunk\ApiTokens;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Auth\Auth;
use Trunk\Auth\Csrf\Csrf;
use Trunk\Auth\Session\Session;
use Trunk\Auth\Token\AccessToken;
use Trunk\Auth\Token\TokenManager;
use Trunk\Auth\User\Authenticatable;
use Trunk\Contracts\Clock;
use Trunk\Http\Exception\HttpException;
use Trunk\Mvc\Responder;

/**
 * GET /account/tokens lists the signed-in user's API tokens; POST creates one (after the password is
 * entered again) and shows it once, in that response only; POST /account/tokens/{id}/revoke ends one.
 * Each rendered form carries a one-time id, so a form sent twice (the browser's reload) never makes a
 * second token.
 * The page is resources/views/account/tokens.tusk.php, the project's own to change.
 *
 * @internal
 */
final readonly class TokenPageController
{
    public const string PATH = '/account/tokens';

    public const string VIEW = 'account/tokens';

    private const array LIFETIMES = [7, 30, 90, 365];

    private const string FORM = 'api_tokens.form';

    /**
     * @param list<string> $abilities what a new token may be allowed; empty means every token has full access (`*`)
     */
    public function __construct(
        private Auth $auth,
        private Csrf $csrf,
        private Session $session,
        private TokenManager $tokens,
        private Responder $responder,
        private Clock $clock,
        private array $abilities,
        private int $maxActive,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $notice = match ($request->getQueryParams()['done'] ?? null) {
            'revoked' => 'The token was revoked. Anything still using it now gets 401.',
            'stale' => 'That form had already been sent (or was opened in another tab), so nothing was created. A token is shown only once.',
            default => '',
        };

        return $this->page($this->user(), notice: $notice);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->user();
        $form = (array) $request->getParsedBody();
        $expected = $this->session->pull(self::FORM);

        if (!\is_string($expected) || !\is_string($form['form'] ?? null) || !hash_equals($expected, $form['form'])) {
            return $this->responder->redirect(self::PATH . '?done=stale', 303);
        }

        $name = \is_string($form['name'] ?? null) ? trim($form['name']) : '';
        $chosen = \is_array($form['abilities'] ?? null) ? array_values(array_filter($form['abilities'], is_string(...))) : [];
        $days = \is_string($form['days'] ?? null) && ctype_digit($form['days']) ? (int) $form['days'] : 0;
        $password = \is_string($form['password'] ?? null) ? $form['password'] : '';
        $errors = [];

        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $name) !== 0) {
            $errors['name'] = 'Give the token a name of 1 to 100 characters, such as "CI" or "Reporting".';
        }

        if ($this->abilities !== [] && ($chosen === [] || array_diff($chosen, $this->abilities) !== [])) {
            $errors['abilities'] = 'Choose what the token may do.';
        }

        if (!\in_array($days, self::LIFETIMES, true)) {
            $errors['days'] = 'Choose when the token expires.';
        }

        if (\count(array_filter($this->tokens->forUser($user), fn(AccessToken $t): bool => $t->isActive($this->clock->now()))) >= $this->maxActive) {
            $errors['form'] = \sprintf('You already have %d active tokens. Revoke one you no longer use first.', $this->maxActive);
        }

        if ($errors === [] && !$this->auth->confirmPassword($password)) {
            $errors['password'] = 'That is not your password.';
        }

        if ($errors !== []) {
            return $this->page($user, errors: $errors, old: ['name' => $name, 'abilities' => $chosen, 'days' => $days], status: 422);
        }

        try {
            $created = $this->tokens->issue($user, $name, $this->abilities === [] ? ['*'] : array_values(array_unique($chosen)), $days * 86400);
        } catch (InvalidArgumentException) {
            return $this->page($user, errors: ['name' => 'Give the token a name of 1 to 100 characters, such as "CI" or "Reporting".'], old: ['name' => $name, 'abilities' => $chosen, 'days' => $days], status: 422);
        }

        // The secret is shown in this response only: it is never stored, in the session or anywhere else.
        return $this->page($user, created: ['name' => $created->token->name, 'token' => $created->plainText, 'expires' => self::date($created->token->expiresAt)]);
    }

    public function revoke(string $id): ResponseInterface
    {
        $user = $this->user();
        $mine = array_filter($this->tokens->forUser($user), static fn(AccessToken $t): bool => hash_equals($t->id, $id));

        if ($mine === []) {
            throw HttpException::notFound();   // someone else's token is answered as one that does not exist
        }

        $this->tokens->revoke($id);

        return $this->responder->redirect(self::PATH . '?done=revoked', 303);
    }

    private function user(): Authenticatable
    {
        return $this->auth->user() ?? throw new HttpException(401, 'Authentication is required.');
    }

    /**
     * @param array<string, string>                                    $errors
     * @param array{name?: string, abilities?: list<string>, days?: int} $old
     * @param array{name: string, token: string, expires: string}|null $created
     */
    private function page(Authenticatable $user, array $errors = [], array $old = [], ?array $created = null, string $notice = '', int $status = 200): ResponseInterface
    {
        $now = $this->clock->now();
        $tokens = $this->tokens->forUser($user);
        usort($tokens, static fn(AccessToken $a, AccessToken $b): int => $b->createdAt <=> $a->createdAt);
        $days = $old['days'] ?? 30;
        $form = bin2hex(random_bytes(16));
        $this->session->put(self::FORM, $form);

        return $this->responder->view(self::VIEW, [
            'csrf' => $this->csrf->token(),
            'form' => $form,
            'path' => self::PATH,
            'created' => $created,
            'notice' => $notice,
            'errors' => $errors,
            'old' => ['name' => $old['name'] ?? ''],
            'fullAccess' => $this->abilities === [],
            'abilities' => array_map(static fn(string $a): array => ['value' => $a, 'checked' => \in_array($a, $old['abilities'] ?? [], true)], $this->abilities),
            'lifetimes' => array_map(static fn(int $d): array => ['days' => $d, 'label' => $d === 365 ? '1 year' : $d . ' days', 'selected' => $d === $days], self::LIFETIMES),
            'tokens' => array_map(static fn(AccessToken $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => $t->abilities === ['*'] ? 'Full access' : implode(', ', $t->abilities),
                'created' => self::date($t->createdAt),
                'lastUsed' => $t->lastUsedAt === null ? 'Never' : self::date($t->lastUsedAt),
                'expires' => $t->expiresAt === null ? 'Never' : self::date($t->expiresAt),
                'status' => $t->revokedAt !== null ? 'Revoked' : ($t->isActive($now) ? 'Active' : 'Expired'),
                'active' => $t->isActive($now),
            ], $tokens),
        ], $status);
    }

    private static function date(?int $timestamp): string
    {
        return $timestamp === null ? 'Never' : gmdate('Y-m-d H:i', $timestamp) . ' UTC';
    }
}

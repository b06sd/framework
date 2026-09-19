<?php

declare(strict_types=1);

namespace Trunk\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Trunk\Auth\Cookie\Cookie;
use Trunk\Auth\Session\Session;
use Trunk\Auth\Session\SessionId;
use Trunk\Auth\Session\SessionRecord;
use Trunk\Auth\Session\SessionStore;
use Trunk\Auth\Settings\SessionSettings;
use Trunk\Auth\Throttle\SessionCreationLimit;
use Trunk\Contracts\Clock;
use Trunk\Http\Server\ServerRequestCreator;

/**
 * Loads the session named by the request's cookie before the handler runs and saves it after. Add it
 * (before `CsrfMiddleware` and `RequireLogin`) as route or group middleware on the routes that use
 * sessions; routes without it pay nothing.
 *
 *  - The cookie is trusted for nothing but its 43-character id; the record must exist in the store,
 *    be inside its idle and absolute lifetimes, and is deleted when it is not.
 *  - Visitors who store nothing get no session row and no cookie, and one address can start only
 *    `auth.throttle.max_new_sessions_per_ip` anonymous sessions per window (429 beyond it).
 *  - A request that fails with an exception saves nothing, except that an ended session is deleted.
 *  - Responses to a signed-in user (and the response that signs them out) get `Cache-Control: no-store`
 *    unless the handler set its own, so the back button cannot show a private page after logout.
 *  - The id changes whenever the session asks for it (`regenerate()`, login, logout), and the old id
 *    is deleted at once.
 *
 * @api
 */
final class SessionMiddleware implements MiddlewareInterface
{
    /** A used session's last-activity stamp is refreshed at most this often, to spare the store. */
    private const int TOUCH_AFTER = 60;

    /** @internal wired by the container */
    public function __construct(
        private readonly Session $session,
        private readonly SessionStore $store,
        private readonly SessionSettings $settings,
        private readonly Clock $clock,
        private readonly SessionCreationLimit $limit,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $now = $this->clock->now();
        $id = $this->cookieId($request);
        $record = $id === null ? null : $this->load($id, $now);

        if ($record === null) {
            $id = null;
        }

        $this->session->start($id, $record, $now);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            // A failed request saves nothing, with one exception: a session that was ended (a signed-out
            // user, a stale login) is really deleted, so the error path cannot keep it alive.
            if ($id !== null && $this->session->wasInvalidated()) {
                $this->store->delete(SessionId::hash($id));
            }

            throw $e;
        }

        $address = $request->getAttribute(ServerRequestCreator::CLIENT_IP_ATTRIBUTE);

        return $this->keepPrivate($this->save($response, $now, \is_string($address) ? $address : null));
    }

    /**
     * A page shown to a signed-in user (or the response that signs them out) must not be kept by the
     * browser or a proxy: otherwise the back button shows the private page to whoever uses the computer
     * next. A response that sets its own Cache-Control keeps it.
     */
    private function keepPrivate(ResponseInterface $response): ResponseInterface
    {
        if (($this->session->wasInvalidated() || \is_string($this->session->internal('user'))) && !$response->hasHeader('Cache-Control')) {
            return $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }

    private function load(string $id, int $now): ?SessionRecord
    {
        $hash = SessionId::hash($id);
        $record = $this->store->read($hash);

        if ($record === null) {
            return null;
        }

        if ($now - $record->lastActivity > $this->settings->idleTimeout || $now - $record->createdAt > $this->settings->lifetime || $record->createdAt > $now + 300) {
            $this->store->delete($hash);

            return null;
        }

        return $record;
    }

    private function save(ResponseInterface $response, int $now, ?string $address): ResponseInterface
    {
        $oldId = $this->session->plainId();
        $renew = $this->session->shouldRegenerate() || $oldId === null;
        $created = $oldId === null || $this->session->hasFreshLifetime() ? $now : $this->session->createdAt();

        if ($oldId !== null && ($this->session->shouldRegenerate() || $this->session->wasInvalidated())) {
            $this->store->delete(SessionId::hash($oldId));
        }

        if (!$this->session->hasContent()) {
            // Nothing left to keep: an ended or emptied session is removed and its cookie forgotten;
            // an anonymous visitor leaves no trace at all.
            if ($oldId === null) {
                return $response->withAddedHeader('Vary', 'Cookie');
            }

            $this->store->delete(SessionId::hash($oldId));

            return $this->withCookie($response->withAddedHeader('Vary', 'Cookie'), Cookie::forget($this->settings->cookieName(), $this->settings->secure, sameSite: $this->settings->sameSite));
        }

        $response = $response->withAddedHeader('Vary', 'Cookie');

        if (!$renew && !$this->session->isDirty() && $now - $this->session->lastActivity() < self::TOUCH_AFTER) {
            return $response;
        }

        if ($oldId === null && !\is_string($this->session->internal('user'))) {
            // A brand-new session for someone who is not signed in: the only kind an outsider can mass-produce.
            $this->limit->claim($address);
        }

        $newId = $renew ? SessionId::generate() : (string) $oldId;
        $this->store->write(SessionId::hash($newId), $this->session->record($created, $now));

        return $renew ? $this->withCookie($response, new Cookie($this->settings->cookieName(), $newId, null, '/', null, $this->settings->secure, true, $this->settings->sameSite)) : $response;
    }

    private function withCookie(ResponseInterface $response, Cookie $cookie): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $cookie->header());
    }

    private function cookieId(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[$this->settings->cookieName()] ?? null;

        return \is_string($value) && SessionId::isValid($value) ? $value : null;
    }
}

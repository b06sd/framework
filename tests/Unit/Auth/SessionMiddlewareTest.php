<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Auth;

use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use stdClass;
use Trunk\Auth\Http\SessionMiddleware;
use Trunk\Auth\Session\ArraySessionStore;
use Trunk\Auth\Session\Session;
use Trunk\Auth\Session\SessionId;
use Trunk\Auth\Session\SessionRecord;
use Trunk\Auth\Settings\SessionSettings;
use Trunk\Auth\Throttle\AttemptCounter;
use Trunk\Auth\Throttle\SessionCreationLimit;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Message\Response;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Support\AuthHarness;

final class SessionMiddlewareTest extends TestCase
{
    private ArraySessionStore $store;

    private \Trunk\Tests\Support\FixedClock $clock;

    private SessionSettings $settings;

    private AuthHarness $harness;

    protected function setUp(): void
    {
        $this->harness = AuthHarness::for('sqlite') ?? throw new LogicException('sqlite is always available');
        $this->store = new ArraySessionStore();
        $this->clock = $this->harness->clock;
        $this->settings = new SessionSettings(store: 'array', secure: false, idleTimeout: 600, lifetime: 3600);
    }

    protected function tearDown(): void
    {
        $this->harness->cleanUp();
    }

    public function test_one_address_can_start_only_so_many_anonymous_sessions_and_nothing_is_stored_beyond_it(): void
    {
        // Arrange: the harness allows 10 new sessions per address per window
        $codes = [];

        // Act
        for ($i = 0; $i < 12; ++$i) {
            try {
                $this->handle(null, static function (Session $s): void {
                    $s->put('k', 'v');
                }, '198.51.100.1');
                $codes[] = 200;
            } catch (HttpException $e) {
                $codes[] = $e->statusCode();
                $retry = $e->headers['Retry-After'] ?? '';
            }
        }
        $other = $this->handle(null, static function (Session $s): void {
            $s->put('k', 'v');
        }, '198.51.100.2');

        // Assert
        self::assertSame([200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 429, 429], $codes);
        self::assertGreaterThan(0, (int) ($retry ?? 0));
        self::assertSame(1, $this->store->prune(\PHP_INT_MAX) - 10, 'ten sessions for the first address, one for the other, none for the refused');
        self::assertNotSame('', $other->getHeaderLine('Set-Cookie'));
    }

    public function test_sessions_created_by_a_login_and_existing_sessions_never_count_against_the_limit(): void
    {
        // Arrange: use up the whole allowance for this address
        for ($i = 0; $i < 10; ++$i) {
            $this->handle(null, static function (Session $s): void {
                $s->put('k', 'v');
            }, '198.51.100.3');
        }

        // Act
        $login = $this->handle(null, static function (Session $s): void {
            $s->regenerate(true);
            $s->setInternal('user', '7');
        }, '198.51.100.3');
        $cookie = $this->cookieValue($login);
        $again = $this->handle($cookie, static function (Session $s): void {
            $s->put('more', 'data');
        }, '198.51.100.3');

        // Assert
        self::assertNotSame('', $login->getHeaderLine('Set-Cookie'), 'a login still gets its session');
        self::assertSame(200, $again->getStatusCode());
    }

    public function test_pages_for_a_signed_in_user_and_the_logout_response_are_not_cacheable_unless_the_handler_says_otherwise(): void
    {
        // Arrange
        $signedIn = $this->handle(null, static function (Session $s): void {
            $s->regenerate(true);
            $s->setInternal('user', '7');
        });
        $cookie = $this->cookieValue($signedIn);

        // Act
        $page = $this->handle($cookie, static function (Session $s): void {
            $s->get('anything');
        });
        $loggedOut = $this->handle($cookie, static function (Session $s): void {
            $s->invalidate();
        });
        $anonymous = $this->handle(null, static function (Session $s): void {
            $s->get('anything');
        });

        // Assert
        self::assertSame('no-store', $signedIn->getHeaderLine('Cache-Control'));
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'), 'the back button must not show a private page');
        self::assertSame('no-store', $loggedOut->getHeaderLine('Cache-Control'));
        self::assertFalse($anonymous->hasHeader('Cache-Control'), 'public pages stay cacheable');
        self::assertSame('Cookie', $page->getHeaderLine('Vary'));
    }

    public function test_a_handler_that_sets_its_own_cache_control_keeps_it(): void
    {
        // Arrange
        $cookie = $this->cookieValue($this->handle(null, static function (Session $s): void {
            $s->regenerate(true);
            $s->setInternal('user', '7');
        }));
        $session = new Session();
        $throttle = $this->harness->settings->throttle;
        $middleware = new SessionMiddleware($session, $this->store, $this->settings, $this->clock, new SessionCreationLimit(new AttemptCounter($this->harness->connection, $throttle, $this->clock), $throttle));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Cache-Control' => 'private, max-age=60']);
            }
        };

        // Act
        $response = $middleware->process(new ServerRequest('GET', 'http://app.test/')->withCookieParams(['session' => $cookie]), $handler);

        // Assert
        self::assertSame('private, max-age=60', $response->getHeaderLine('Cache-Control'));
    }

    public function test_a_visitor_who_stores_nothing_gets_no_session_and_no_cookie(): void
    {
        // Act
        $response = $this->handle(null, static fn(Session $s): string => 'x');

        // Assert
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertSame('Cookie', $response->getHeaderLine('Vary'));
        self::assertSame(0, $this->store->prune(\PHP_INT_MAX));
    }

    public function test_storing_a_value_sets_one_strict_cookie_and_a_later_request_sees_the_value(): void
    {
        // Act
        $first = $this->handle(null, static function (Session $s): void {
            $s->put('cart', ['a' => 1]);
        });
        $cookie = $this->cookieValue($first);
        $seen = null;
        $second = $this->handle($cookie, static function (Session $s) use (&$seen): void {
            $seen = $s->get('cart');
        });

        // Assert
        self::assertCount(1, $first->getHeader('Set-Cookie'));
        self::assertMatchesRegularExpression('/^session=[A-Za-z0-9_-]{43}; Path=\/; HttpOnly; SameSite=Lax$/', $first->getHeaderLine('Set-Cookie'));
        self::assertSame(['a' => 1], $seen);
        self::assertSame([], $second->getHeader('Set-Cookie'), 'an unchanged, recently used session is not rewritten');
    }

    public function test_a_secure_cookie_is_host_prefixed_and_secure(): void
    {
        // Arrange
        $this->settings = new SessionSettings(store: 'array', secure: true);

        // Act
        $response = $this->handle(null, static function (Session $s): void {
            $s->put('k', 'v');
        });

        // Assert
        self::assertMatchesRegularExpression('/^__Host-session=[A-Za-z0-9_-]{43}; Path=\/; Secure; HttpOnly; SameSite=Lax$/', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_an_id_supplied_by_the_client_is_never_adopted(): void
    {
        // Arrange
        $attackerId = SessionId::generate();

        // Act
        $response = $this->handle($attackerId, static function (Session $s): void {
            $s->put('user', '1');
        });

        // Assert
        self::assertNotSame($attackerId, $this->cookieValue($response), 'session fixation: the client chooses nothing');
        self::assertNull($this->store->read(SessionId::hash($attackerId)));
    }

    public function test_malformed_cookie_values_are_ignored_and_never_reach_the_store(): void
    {
        // Act & Assert
        foreach (['', 'short', str_repeat('a', 44), str_repeat('=', 43), "abc\r\nSet-Cookie: x=y", str_repeat('é', 43), '../../etc/passwd', "\0"] as $bad) {
            $seen = 'unset';
            $this->handle($bad, static function (Session $s) use (&$seen): void {
                $seen = $s->get('anything', 'none');
            });
            self::assertSame('none', $seen);
        }

        self::assertSame(0, $this->store->prune(\PHP_INT_MAX));
    }

    public function test_an_idle_session_ends_and_its_record_is_deleted(): void
    {
        // Arrange
        $cookie = $this->cookieValue($this->handle(null, static function (Session $s): void {
            $s->put('user', '1');
        }));

        // Act
        $this->clock->advance(601);
        $seen = 'unset';
        $this->handle($cookie, static function (Session $s) use (&$seen): void {
            $seen = $s->get('user', 'none');
        });

        // Assert
        self::assertSame('none', $seen);
        self::assertNull($this->store->read(SessionId::hash($cookie)));
    }

    public function test_activity_keeps_a_session_alive_but_never_past_its_absolute_lifetime(): void
    {
        // Arrange
        $cookie = $this->cookieValue($this->handle(null, static function (Session $s): void {
            $s->put('user', '1');
        }));

        // Act: a request every 500 seconds keeps the idle timer fresh
        $alive = 0;
        for ($i = 0; $i < 6; ++$i) {
            $this->clock->advance(500);
            $seen = null;
            $this->handle($cookie, static function (Session $s) use (&$seen): void {
                $seen = $s->get('user');
            });
            $alive += $seen === '1' ? 1 : 0;
        }

        // Assert: 3000 seconds in, still alive; the 3600 second lifetime ends it at the next request
        self::assertSame(6, $alive);
        $this->clock->advance(700);
        $seen = 'unset';
        $this->handle($cookie, static function (Session $s) use (&$seen): void {
            $seen = $s->get('user', 'ended');
        });
        self::assertSame('ended', $seen);
    }

    public function test_regenerating_moves_the_data_to_a_new_id_and_kills_the_old_one(): void
    {
        // Arrange
        $old = $this->cookieValue($this->handle(null, static function (Session $s): void {
            $s->put('user', '1');
        }));

        // Act
        $response = $this->handle($old, static function (Session $s): void {
            $s->regenerate();
        });
        $new = $this->cookieValue($response);
        $seenOld = 'unset';
        $this->handle($old, static function (Session $s) use (&$seenOld): void {
            $seenOld = $s->get('user', 'none');
        });
        $seenNew = null;
        $this->handle($new, static function (Session $s) use (&$seenNew): void {
            $seenNew = $s->get('user');
        });

        // Assert
        self::assertNotSame($old, $new);
        self::assertSame('none', $seenOld);
        self::assertSame('1', $seenNew);
    }

    public function test_a_regenerated_session_keeps_its_original_start_unless_a_fresh_lifetime_is_asked_for(): void
    {
        // Arrange
        $first = $this->cookieValue($this->handle(null, static function (Session $s): void {
            $s->put('k', 'v');
        }));
        $this->clock->advance(400);

        // Act
        $kept = $this->cookieValue($this->handle($first, static function (Session $s): void {
            $s->regenerate();
        }));
        $keptStart = $this->store->read(SessionId::hash($kept))?->createdAt;
        $fresh = $this->cookieValue($this->handle($kept, static function (Session $s): void {
            $s->regenerate(freshLifetime: true);
        }));

        // Assert
        self::assertSame(1_800_000_000, $keptStart);
        self::assertSame(1_800_000_400, $this->store->read(SessionId::hash($fresh))?->createdAt);
    }

    public function test_invalidating_deletes_the_record_and_expires_the_cookie(): void
    {
        // Arrange
        $cookie = $this->cookieValue($this->handle(null, static function (Session $s): void {
            $s->put('user', '1');
        }));

        // Act
        $response = $this->handle($cookie, static function (Session $s): void {
            $s->invalidate();
        });

        // Assert
        self::assertNull($this->store->read(SessionId::hash($cookie)));
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('session=;', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_emptying_a_session_removes_the_stored_record_instead_of_leaving_stale_data(): void
    {
        // Arrange
        $cookie = $this->cookieValue($this->handle(null, static function (Session $s): void {
            $s->put('secret', 'value');
        }));

        // Act
        $response = $this->handle($cookie, static function (Session $s): void {
            $s->forget('secret');
        });

        // Assert
        self::assertNull($this->store->read(SessionId::hash($cookie)));
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_flash_data_is_available_for_exactly_one_following_request(): void
    {
        // Arrange
        $cookie = $this->cookieValue($this->handle(null, static function (Session $s): void {
            $s->flash('notice', 'Saved');
        }));

        // Act
        $second = $third = 'unset';
        $secondResponse = $this->handle($cookie, static function (Session $s) use (&$second): void {
            $second = $s->flashed('notice', 'none');
        });
        $this->handle($cookie, static function (Session $s) use (&$third): void {
            $third = $s->flashed('notice', 'none');
        });

        // Assert
        self::assertSame('Saved', $second);
        self::assertSame('none', $third);
        self::assertSame([], $secondResponse->getHeader('Set-Cookie'));
    }

    public function test_a_record_from_the_future_is_refused(): void
    {
        // Arrange
        $id = SessionId::generate();
        $this->store->write(SessionId::hash($id), new SessionRecord(['user' => '1'], $this->clock->now() + 10_000, $this->clock->now()));

        // Act
        $seen = 'unset';
        $this->handle($id, static function (Session $s) use (&$seen): void {
            $seen = $s->get('user', 'none');
        });

        // Assert
        self::assertSame('none', $seen);
    }

    public function test_an_exception_in_the_handler_saves_nothing(): void
    {
        // Act
        try {
            $this->handle(null, static function (Session $s): void {
                $s->put('half', 'done');

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        // Assert
        self::assertSame(0, $this->store->prune(\PHP_INT_MAX));
    }

    public function test_the_session_api_refuses_unsafe_use_with_actionable_errors(): void
    {
        // Arrange
        $unstarted = new Session();

        // Act & Assert
        try {
            $unstarted->get('x');
            self::fail('expected a LogicException');
        } catch (LogicException $e) {
            self::assertStringContainsString('SessionMiddleware', $e->getMessage());
        }

        $started = new Session();
        $started->start(null, null, 1);

        foreach ([['_trunk.user', 'x'], ['', 'x'], [str_repeat('k', 129), 'x'], ["a\0b", 'x'], ['ok', new stdClass()], ['ok', fopen('php://memory', 'r')], ['ok', NAN], ['ok', "\xB1\x31"], ['ok', ['a' => [new stdClass()]]]] as [$key, $value]) {
            try {
                $started->put($key, $value);
                self::fail('expected a refusal for ' . get_debug_type($value));
            } catch (InvalidArgumentException) {
                self::assertFalse($started->has('ok'));
            }
        }

        self::assertSame([], $started->all());
    }

    private function handle(?string $cookie, Closure $inside, string $address = '203.0.113.9'): ResponseInterface
    {
        $session = new Session();
        $throttle = $this->harness->settings->throttle;
        $middleware = new SessionMiddleware($session, $this->store, $this->settings, $this->clock, new SessionCreationLimit(new AttemptCounter($this->harness->connection, $throttle, $this->clock), $throttle));
        $request = new ServerRequest('GET', 'http://app.test/')->withAttribute('client_ip', $address);

        if ($cookie !== null) {
            $request = $request->withCookieParams([$this->settings->cookieName() => $cookie]);
        }

        $handler = new class ($session, $inside) implements RequestHandlerInterface {
            public function __construct(private Session $session, private Closure $inside) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->inside)($this->session);

                return new Response(200);
            }
        };

        return $middleware->process($request, $handler);
    }

    private function cookieValue(ResponseInterface $response): string
    {
        self::assertNotSame('', $response->getHeaderLine('Set-Cookie'), 'expected a Set-Cookie header');
        $matched = preg_match('/^(?:__Host-)?session=([A-Za-z0-9_-]{43});/', $response->getHeaderLine('Set-Cookie'), $m);
        self::assertSame(1, $matched);

        return $m[1];
    }
}

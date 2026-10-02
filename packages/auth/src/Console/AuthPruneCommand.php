<?php

declare(strict_types=1);

namespace Trunk\Auth\Console;

use Trunk\Auth\Link\OneTimeLinks;
use Trunk\Auth\Session\SessionStore;
use Trunk\Auth\Settings\SessionSettings;
use Trunk\Auth\Throttle\LoginThrottle;
use Trunk\Auth\Token\TokenStore;
use Trunk\Contracts\Clock;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;

/**
 * `trunk auth:prune` deletes what can no longer be used: sessions past their idle timeout, expired or
 * revoked tokens (kept for a week for audit), finished throttle windows and expired one-time links.
 * Run it from cron.
 */
final readonly class AuthPruneCommand implements Command
{
    private const int TOKEN_GRACE = 604_800;

    public function __construct(private SessionStore $sessions, private SessionSettings $settings, private TokenStore $tokens, private LoginThrottle $throttle, private Clock $clock, private OneTimeLinks $links) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('auth:prune', 'Delete expired sessions, tokens, throttle counters and links');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $now = $this->clock->now();
        $sessions = $this->sessions->prune($now - $this->settings->idleTimeout);
        $tokens = $this->tokens->prune($now - self::TOKEN_GRACE);
        $throttles = $this->throttle->prune();
        $links = $this->links->prune($now);
        $output->success(\sprintf('Removed %d session(s), %d token(s), %d throttle counter(s) and %d link(s).', $sessions, $tokens, $throttles, $links));

        return 0;
    }
}

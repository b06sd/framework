<?php

declare(strict_types=1);

namespace Trunk\Auth\Console;

use InvalidArgumentException;
use Trunk\Auth\Token\TokenManager;
use Trunk\Auth\User\UserProvider;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;

/**
 * `trunk auth:token <user-id> <name>` issues an API token and prints it once. The token is the only
 * copy: the database keeps a hash.
 */
final readonly class AuthTokenCommand implements Command
{
    public function __construct(private UserProvider $users, private TokenManager $tokens) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'auth:token',
            'Issue an API token for a user (shown once)',
            ['user-id' => 'the user\'s id', 'name' => 'a label for the token, e.g. "ci"'],
            ['--abilities=a,b' => 'what the token may do (default: everything)', '--ttl=seconds' => 'lifetime in seconds (0 = never expires; default auth.tokens.ttl)'],
            2,
        );
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $user = $this->users->byId((string) $input->argument(0));

        if ($user === null) {
            throw new CommandFailedException('No user with that id.');
        }

        $abilities = $input->option('abilities') === null ? ['*'] : array_values(array_filter(array_map('trim', explode(',', (string) $input->option('abilities'))), static fn(string $a): bool => $a !== ''));
        $ttl = $input->option('ttl');

        if ($ttl !== null && !ctype_digit($ttl)) {
            throw new CommandFailedException('--ttl must be a number of seconds.');
        }

        try {
            $token = $this->tokens->issue($user, (string) $input->argument(1), $abilities, $ttl === null ? null : (int) $ttl);
        } catch (InvalidArgumentException $e) {
            throw new CommandFailedException($e->getMessage(), 0, $e);
        }

        $output->success('Token created. Copy it now; it cannot be shown again:');
        $output->line('');
        $output->line($token->plainText);
        $output->line('');
        $output->line('Use it as the header  Authorization: Bearer <token>');

        return 0;
    }
}

# Command line reference

Outside a project run `trunk new ...` (after `composer global require trunkphp/framework`); inside one, `php vendor/bin/trunk <command>`. `trunk help <command>` shows usage; `-v` shows technical details of a failure. Which commands exist depends on the capabilities enabled (and `console` must be enabled for those a package adds).

## Project

| Command | Does |
| --- | --- |
| `new <name> [--type=api\|web\|self-contained\|cli\|worker] [--repository=<path>] [--force]` | Create a project |
| `serve [--host=127.0.0.1] [--port=8006]` | PHP development server with `APP_ENV=local` (port also from `APP_PORT`) |
| `doctor` | Health check: PHP, extensions, Composer packages each capability needs, config, `.env`, build freshness, log directory, module order, permissions, third-party capabilities |
| `build` | Validate and compile for production into `build/` |
| `route:list` | Every route, handler, how arguments bind, and middleware |
| `test [-- phpunit args]` | Run the project's PHPUnit tests |

## Capabilities

| Command | Does |
| --- | --- |
| `package:list` | What exists and what this app uses |
| `package:install <id \| vendor/name[:constraint]>` | Enable a capability (installing its Composer packages first), or `composer require` a package that provides one |
| `package:remove <id> [--purge]` | Disable a capability (refused while another needs it) |
| `package:sync` | Add or remove integration modules (e.g. `cache:clear` appears when cache and console are both enabled) |

## Generators (never overwrite)

`make:controller`, `make:middleware`, `make:service`, `make:command`, `make:module`, `make:test`, `make:entity` (orm), `make:migration` (database), `make:job` (queue). Each prints where to register or use the result.

## Database, ORM, cache

`migrate`, `migrate:status`, `migrate:rollback [--step=N]`, `migrate:fresh` (never in production), `orm:validate`, `cache:clear`.

## Queue

`queue:table`, `queue:work [--queue=a,b] [--once] [--stop-when-empty] [--max-jobs=N] [--max-time=S] [--memory=MB]`, `queue:failed`, `queue:retry <id|all>`, `queue:flush`.

## Auth

`auth:table [--users]`, `auth:token <user-id> <name> [--abilities=a,b] [--ttl=seconds]`, `auth:prune`.

## Your own commands

```php
final readonly class CreateUser implements Command
{
    public function __construct(private Connection $db, private PasswordHasher $hasher) {}   // injected

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('user:create', 'Create a user', ['name' => 'Display name', 'email' => 'Email'], requiredArguments: 2);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        // $input->argument(0), $input->option('x'), $input->flag('y'); $output->success(), line(), table(), warning()...
        return 0;
    }
}
// AppModule implements CommandProvider:  $commands->add(CreateUser::class);
```

Exit code 0 is success. Throw `CommandFailedException` for a user-facing failure (message shown, exit 1) and `UsageException` for wrong arguments.

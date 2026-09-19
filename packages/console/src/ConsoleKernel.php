<?php

declare(strict_types=1);

namespace Trunk\Console;

use Closure;
use Throwable;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Console\Input\Input;
use Trunk\Console\Output\Output;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\Exception\UsageException;
use Trunk\Contracts\Kernel;

/**
 * Entry point for the command line. `handle()` returns an exit code (0 ok, 1 failure, 2 usage);
 * `run()` is the only place that exits the process.
 */
final class ConsoleKernel implements Kernel
{
    public const string VERSION = '0.1.0';

    /**
     * @param array<string, Closure(): Command>      $builtIn             built-in commands, constructed lazily
     * @param (Closure(): array<string, Command>)|null $applicationCommands commands contributed by the application's modules
     */
    public function __construct(
        private readonly array $builtIn,
        private readonly ?Closure $applicationCommands,
        private readonly Output $output,
    ) {}

    public function run(): void
    {
        $argv = $_SERVER['argv'] ?? [];

        exit($this->handle(\is_array($argv) ? array_values(array_filter($argv, is_string(...))) : []));
    }

    /**
     * @param list<string> $argv script name first
     */
    public function handle(array $argv): int
    {
        $input = Input::fromArgv($argv);

        try {
            if ($input->wantsVersion()) {
                $this->output->line('trunk ' . self::VERSION);

                return 0;
            }

            if ($input->command === null || $input->command === 'list') {
                return $this->list();
            }

            if ($input->command === 'help') {
                return $this->help($input->argument(0));
            }

            $command = $this->find($input->command);
            $definition = $command->definition();

            if ($input->wantsHelp()) {
                $this->describe($definition);

                return 0;
            }

            if (\count($input->arguments) < $definition->requiredArguments) {
                $missing = array_keys($definition->arguments)[\count($input->arguments)] ?? 'argument';

                throw new UsageException(\sprintf('Missing argument <%s>. Usage: %s', $missing, $definition->usage()));
            }

            return $command->handle($input, $this->output);
        } catch (UsageException $e) {
            $this->output->error($e->getMessage());
            $this->output->error('Run `trunk help` to see what is available.');

            return 2;
        } catch (Throwable $e) {
            return $this->fail($e, $input->verbose());
        }
    }

    private function find(string $name): Command
    {
        if (isset($this->builtIn[$name])) {
            return ($this->builtIn[$name])();
        }

        $application = $this->applicationCommands === null ? [] : ($this->applicationCommands)();

        if (isset($application[$name])) {
            return $application[$name];
        }

        $known = [...array_keys($this->builtIn), ...array_keys($application)];
        $closest = $this->closest($name, $known);

        throw new UsageException(\sprintf('There is no command "%s".%s', $name, $closest === null ? '' : \sprintf(' Did you mean "%s"?', $closest)));
    }

    private function list(): int
    {
        $definitions = array_map(static fn(Closure $make): CommandDefinition => $make()->definition(), $this->builtIn);
        $this->output->title('Trunk ' . self::VERSION);
        $this->output->line();
        $this->output->line('Usage: trunk <command> [arguments] [--options]');
        $this->output->line();

        if ($this->applicationCommands !== null) {
            try {
                foreach (($this->applicationCommands)() as $name => $command) {
                    $definitions[$name] = $command->definition();
                }
            } catch (Throwable $e) {
                $this->output->warning('Application commands are unavailable: ' . $e->getMessage());
            }
        }

        ksort($definitions);
        $this->output->table(['Command', 'Description'], array_values(array_map(static fn(CommandDefinition $d): array => [$d->name, $d->description], $definitions)));

        return 0;
    }

    private function help(?string $name): int
    {
        if ($name === null) {
            return $this->list();
        }

        $this->describe($this->find($name)->definition());

        return 0;
    }

    private function describe(CommandDefinition $definition): void
    {
        $this->output->title($definition->name);
        $this->output->line($definition->description);
        $this->output->line();
        $this->output->line('Usage: ' . $definition->usage());

        foreach (['Arguments' => $definition->arguments, 'Options' => $definition->options] as $heading => $items) {
            if ($items === []) {
                continue;
            }

            $this->output->line();
            $this->output->line($heading . ':');
            $this->output->table(['Name', 'Description'], array_map(static fn(string $name, string $description): array => [$name, $description], array_keys($items), $items));
        }
    }

    private function fail(Throwable $e, bool $verbose): int
    {
        $this->output->error('Something went wrong:');
        $this->output->error('  ' . $e->getMessage());

        if ($e instanceof CompilationException) {
            foreach ($e->errors as $error) {
                $this->output->error('  - ' . $error);
            }
        }

        if ($verbose) {
            $this->output->error('');
            $this->output->error($e::class . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->output->error($e->getTraceAsString());
        } else {
            $this->output->error('Run again with -v to see the technical details.');
        }

        return 1;
    }

    /**
     * @param list<string> $known
     */
    private function closest(string $name, array $known): ?string
    {
        $best = null;
        $distance = 4;

        foreach ($known as $candidate) {
            $d = levenshtein($name, $candidate);

            if ($d < $distance) {
                $best = $candidate;
                $distance = $d;
            }
        }

        return $best;
    }
}

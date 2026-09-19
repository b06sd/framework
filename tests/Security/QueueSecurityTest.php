<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use InvalidArgumentException;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Trunk\Queue\Compiler\JobCodeGenerator;
use Trunk\Queue\Exception\QueueException;
use Trunk\Queue\Job\Field;
use Trunk\Queue\Job\JobMetadata;
use Trunk\Queue\Job\JobOptions;
use Trunk\Queue\Job\PayloadType;
use Trunk\Queue\Worker\Outcome;
use Trunk\Queue\Worker\WorkerOptions;
use Trunk\Tests\Fixtures\Queue\WelcomeJob;
use Trunk\Tests\Support\QueueHarness;

/**
 * Stored payloads are data: never deserialised, never used to pick a class, never echoed.
 */
final class QueueSecurityTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield 'php object' => ['O:8:"stdClass":1:{s:1:"a";s:3:"evil";}'];
        yield 'phar style' => ['phar://evil.phar/x'];
        yield 'class name field' => ['{"userId":1,"__class":"Evil\\\\Thing"}'];
        yield 'class name as plan' => ['{"userId":1,"plan":"Evil\\\\Thing"}'];
        yield 'nested bomb' => [str_repeat('[', 500) . str_repeat(']', 500)];
        yield 'huge number' => ['{"userId":1e999}'];
        yield 'sql' => ['{"userId":"1; DROP TABLE trunk_jobs"}'];
    }

    #[DataProvider('payloads')]
    public function test_hostile_stored_payloads_never_reach_a_handler_and_fail_without_retries(string $payload): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->driver->push('mail', WelcomeJob::class, $payload, 0, $h->clock->now());
        $loaded = [];
        spl_autoload_register($recorder = static function (string $class) use (&$loaded): void {
            $loaded[] = $class;
        }, true, true);

        try {
            // Act
            $outcome = $h->worker->processNext(['mail']);
        } finally {
            spl_autoload_unregister($recorder);
        }

        // Assert
        self::assertSame(Outcome::Failed, $outcome);
        self::assertSame([], $h->recorder->events);
        self::assertSame([], array_values(array_filter($loaded, static fn(string $c): bool => str_contains($c, 'Evil'))));
        self::assertNull($h->driver->failed()[0]->message);
    }

    public function test_job_names_in_the_table_are_only_ever_lookup_keys(): void
    {
        // Arrange
        $h = new QueueHarness();
        $names = ['stdClass', 'Trunk\\Tests\\Fixtures\\Queue\\Recorder', 'DateTime', 'Evil\\Thing', '../../etc/passwd', 'WelcomeJob', ''];
        foreach ($names as $name) {
            $h->driver->push('mail', $name, '{}', 0, $h->clock->now());
        }

        // Act
        $outcomes = [];
        while (($outcome = $h->worker->processNext(['mail'])) !== null) {
            $outcomes[] = $outcome;
        }

        // Assert
        self::assertCount(\count($names), $outcomes);
        self::assertSame([], array_filter($outcomes, static fn(Outcome $o): bool => $o !== Outcome::Failed));
        self::assertSame([], $h->recorder->events);
        self::assertSame(array_fill(0, \count($names), \Trunk\Queue\Exception\UnknownJob::class), array_map(static fn($f): string => $f->exception, $h->driver->failed()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileQueueNames(): iterable
    {
        yield 'quote' => ["mail'; DROP TABLE trunk_jobs; --"];
        yield 'space' => ['my queue'];
        yield 'uppercase' => ['Mail'];
        yield 'path' => ['../mail'];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'newline' => ["mail\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('hostileQueueNames')]
    public function test_queue_names_from_callers_and_the_cli_are_validated(string $name): void
    {
        // Arrange
        $h = new QueueHarness();
        $rejected = 0;

        // Act
        try {
            $h->queue->dispatch(new WelcomeJob(1), queue: $name);
        } catch (QueueException) {
            ++$rejected;
        }

        try {
            new WorkerOptions([$name]);
        } catch (InvalidArgumentException) {
            ++$rejected;
        }

        try {
            new JobOptions(queue: $name);
        } catch (InvalidArgumentException) {
            ++$rejected;
        }

        // Assert
        self::assertSame(3, $rejected);
    }

    public function test_the_generator_refuses_field_names_that_could_break_out_of_generated_code(): void
    {
        // Arrange
        $rejected = 0;

        foreach (["id; system('id')", "a'] => 1, 'b", 'a b', '$x'] as $field) {
            $metadata = new JobMetadata(WelcomeJob::class, WelcomeJob::class, new JobOptions(), [new Field($field, PayloadType::Int, false, false)], []);

            // Act
            try {
                new JobCodeGenerator()->generate([WelcomeJob::class => $metadata]);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(4, $rejected);
    }

    public function test_the_queue_package_has_no_dynamic_code_execution_or_unserialization(): void
    {
        // Arrange
        $forbidden = ['eval(', 'unserialize(', 'shell_exec(', 'system(', 'passthru(', 'proc_open(', 'popen(', 'setAccessible(', 'create_function', 'assert(', 'include(', 'include_once('];
        $hits = [];

        // Act
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../packages/queue/src')) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $code = implode('', array_map(static fn(PhpToken $t): string => $t->is([T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) ? '' : $t->text, PhpToken::tokenize((string) file_get_contents($file->getPathname()))));

            foreach ($forbidden as $needle) {
                if (str_contains($code, $needle)) {
                    $hits[] = $file->getFilename() . ': ' . $needle;
                }
            }
        }

        // Assert
        self::assertSame([], $hits);
    }
}

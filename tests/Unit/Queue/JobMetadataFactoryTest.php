<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Queue;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Trunk\Queue\Exception\JobMappingException;
use Trunk\Queue\Job\JobMetadataFactory;
use Trunk\Queue\Job\JobOptions;
use Trunk\Queue\Job\PayloadType;
use Trunk\Tests\Fixtures\Queue\Bad\BadOptionsJob;
use Trunk\Tests\Fixtures\Queue\Bad\BrokenJob;
use Trunk\Tests\Fixtures\Queue\Bad\NoHandleJob;
use Trunk\Tests\Fixtures\Queue\FailingJob;
use Trunk\Tests\Fixtures\Queue\Plan;
use Trunk\Tests\Fixtures\Queue\Recorder;
use Trunk\Tests\Fixtures\Queue\WelcomeJob;

final class JobMetadataFactoryTest extends TestCase
{
    public function test_a_valid_job_compiles_to_metadata(): void
    {
        // Arrange & Act
        $metadata = new JobMetadataFactory()->fromClasses([WelcomeJob::class, FailingJob::class]);
        $welcome = $metadata[WelcomeJob::class];

        // Assert
        self::assertSame(['userId', 'plan', 'note', 'ratio', 'flag', 'tags', 'at'], array_map(static fn($f): string => $f->name, $welcome->fields));
        self::assertSame(PayloadType::Enum, $welcome->fields[1]->type);
        self::assertSame(Plan::class, $welcome->fields[1]->enum);
        self::assertTrue($welcome->fields[2]->nullable);
        self::assertFalse($welcome->fields[0]->optional);
        self::assertTrue($welcome->fields[1]->optional);
        self::assertSame(PayloadType::DateTime, $welcome->fields[6]->type);
        self::assertSame([Recorder::class], $welcome->dependencies);
        self::assertSame('mail', $welcome->options->queue);
        self::assertSame(4, $welcome->options->tries);
        self::assertEquals(new JobOptions(tries: 3, backoff: [10, 60]), $metadata[FailingJob::class]->options);
    }

    public function test_every_problem_is_reported_together_with_the_fix(): void
    {
        // Arrange & Act
        try {
            new JobMetadataFactory()->fromClasses([BrokenJob::class]);
            self::fail('Expected a JobMappingException.');
        } catch (JobMappingException $e) {
            $all = implode("\n", $e->errors);

            // Assert
            self::assertStringContainsString('constructor parameter "entity" has type stdClass, which cannot be queued', $all);
            self::assertStringContainsString('pass an id instead of an object', $all);
            self::assertStringContainsString('"either" needs a single declared type', $all);
            self::assertStringContainsString('"plain" needs a public property', $all);
            self::assertStringContainsString('"callback" has type Closure', $all);
            self::assertStringContainsString('asks for the container', $all);
            self::assertStringContainsString('handle() parameter "name" must be a class or interface type', $all);
            self::assertStringContainsString('handle() parameter "maybe" must be a class or interface type', $all);
        }
    }

    public function test_missing_handle_bad_options_and_bad_class_names_are_rejected(): void
    {
        // Arrange
        $errors = [];

        // Act
        foreach ([[NoHandleJob::class], [BadOptionsJob::class], ['Nope\\Missing'], [stdClass::class], ['../../etc/passwd']] as $classes) {
            try {
                new JobMetadataFactory()->fromClasses($classes);
            } catch (JobMappingException $e) {
                array_push($errors, ...$e->errors);
            }
        }
        $all = implode("\n", $errors);

        // Assert
        self::assertStringContainsString('needs a public `handle()` method', $all);
        self::assertStringContainsString('options() must return a', $all);
        self::assertStringContainsString('tries must be between 1 and 100', $all);
        self::assertStringContainsString('is not a class', $all);
        self::assertStringContainsString('must implement', $all);
        self::assertStringNotContainsString('/etc/passwd', str_replace('?', '', $all) === $all ? '' : $all);
    }

    public function test_options_are_validated_and_backoff_repeats_its_last_value(): void
    {
        // Arrange
        $options = new JobOptions(tries: 5, backoff: [5, 30]);
        $rejected = 0;

        // Act
        foreach ([[0], [101], [3, [-1]], [3, [10], 0], [3, [10], 3601], [3, [10], 60, 'Bad Queue'], [3, [10], 60, '']] as $args) {
            try {
                new JobOptions(...$args);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(7, $rejected);
        self::assertSame([5, 30, 30, 30], [$options->delayAfter(1), $options->delayAfter(2), $options->delayAfter(3), $options->delayAfter(9)]);
        self::assertSame(0, new JobOptions(backoff: [])->delayAfter(1));
    }
}

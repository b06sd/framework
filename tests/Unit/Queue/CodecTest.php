<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Queue;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Trunk\Queue\Compiler\JobArtifact;
use Trunk\Queue\Compiler\JobCodeGenerator;
use Trunk\Queue\Exception\InvalidPayload;
use Trunk\Queue\Exception\UnknownJob;
use Trunk\Queue\Job\DevelopmentJobRegistry;
use Trunk\Queue\Job\JobMetadataFactory;
use Trunk\Queue\Job\JobRegistry;
use Trunk\Queue\Job\PayloadCodec;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Queue\FailingJob;
use Trunk\Tests\Fixtures\Queue\Plan;
use Trunk\Tests\Fixtures\Queue\WelcomeJob;

final class CodecTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-queue-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    public function test_jobs_round_trip_identically_in_development_and_compiled_modes(): void
    {
        // Arrange
        $job = new WelcomeJob(7, Plan::Pro, 'hi', 2.5, true, ['a' => [1, 2, ['x' => null]], 'b' => 'c'], new DateTimeImmutable('2026-03-04 05:06:07.123456 Europe/Berlin'));
        $codec = new PayloadCodec();
        $payloads = [];

        foreach ($this->registries() as $mode => $registry) {
            // Act
            $definition = $registry->definition(WelcomeJob::class);
            $json = $codec->encode($definition->encode($job), WelcomeJob::class);
            $decoded = $definition->decode($codec->parse($json, WelcomeJob::class));

            // Assert
            $payloads[$mode] = $json;
            self::assertInstanceOf(WelcomeJob::class, $decoded, $mode);
            self::assertSame(7, $decoded->userId, $mode);
            self::assertSame(Plan::Pro, $decoded->plan, $mode);
            self::assertSame('hi', $decoded->note, $mode);
            self::assertSame(2.5, $decoded->ratio, $mode);
            self::assertTrue($decoded->flag, $mode);
            self::assertSame(['a' => [1, 2, ['x' => null]], 'b' => 'c'], $decoded->tags, $mode);
            self::assertNotNull($decoded->at, $mode);
            self::assertSame('2026-03-04 04:06:07.123456', $decoded->at->format('Y-m-d H:i:s.u'), $mode);
            self::assertSame('UTC', $decoded->at->getTimezone()->getName(), $mode);
        }

        self::assertSame($payloads['development'], $payloads['compiled']);
        self::assertStringContainsString('"plan":"pro"', $payloads['compiled']);
        self::assertStringContainsString('2026-03-04T04:06:07.123456Z', $payloads['compiled']);
    }

    public function test_defaults_apply_when_optional_fields_are_missing(): void
    {
        foreach ($this->registries() as $mode => $registry) {
            // Arrange & Act
            $job = $registry->definition(WelcomeJob::class)->decode(['userId' => 3]);

            // Assert
            self::assertInstanceOf(WelcomeJob::class, $job, $mode);
            self::assertSame(Plan::Free, $job->plan, $mode);
            self::assertNull($job->note, $mode);
            self::assertSame([], $job->tags, $mode);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badPayloads(): iterable
    {
        yield 'missing required' => [[], 'field "userId" is missing'];
        yield 'wrong int type' => [['userId' => '7'], 'field "userId" must be int'];
        yield 'float for int' => [['userId' => 7.5], 'field "userId" must be int'];
        yield 'null for required' => [['userId' => null], 'field "userId" must be int but is null'];
        yield 'bad enum' => [['userId' => 1, 'plan' => 'enterprise'], 'field "plan" must be enum'];
        yield 'array for string' => [['userId' => 1, 'note' => ['x']], 'field "note" must be string'];
        yield 'bool from int' => [['userId' => 1, 'flag' => 1], 'field "flag" must be bool'];
        yield 'string for array' => [['userId' => 1, 'tags' => 'x'], 'field "tags" must be array'];
        yield 'bad date' => [['userId' => 1, 'at' => 'yesterday'], 'field "at" must be datetime'];
        yield 'date without zone marker' => [['userId' => 1, 'at' => '2026-03-04 05:06:07'], 'field "at" must be datetime'];
        yield 'unknown field' => [['userId' => 1, 'isAdmin' => true], 'a field that the job does not declare'];
        yield 'object-like class name' => [['userId' => 1, 'plan' => 'O:8:"stdClass":0:{}'], 'field "plan" must be enum'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('badPayloads')]
    public function test_decoding_is_strict_and_errors_never_echo_values(array $data, string $message): void
    {
        foreach ($this->registries() as $mode => $registry) {
            // Arrange & Act
            try {
                $registry->definition(WelcomeJob::class)->decode($data);
                self::fail('Expected an InvalidPayload in ' . $mode);
            } catch (InvalidPayload $e) {
                // Assert
                self::assertStringContainsString($message, $e->getMessage(), $mode);
                self::assertStringNotContainsString('stdClass', $e->getMessage(), $mode);
                self::assertStringNotContainsString('yesterday', $e->getMessage(), $mode);
                self::assertStringNotContainsString('enterprise', $e->getMessage(), $mode);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileJson(): iterable
    {
        yield 'not json' => ['{nope'];
        yield 'php serialized object' => ['O:8:"stdClass":1:{s:1:"a";i:1;}'];
        yield 'a list' => ['[1,2,3]'];
        yield 'scalar' => ['42'];
        yield 'null' => ['null'];
        yield 'too deep' => [str_repeat('{"a":', 40) . '1' . str_repeat('}', 40)];
        yield 'oversized' => ['{"userId":1,"note":"' . str_repeat('x', 70000) . '"}'];
        yield 'invalid utf8' => ["{\"userId\":1,\"note\":\"\xC3\x28\"}"];
    }

    #[DataProvider('hostileJson')]
    public function test_hostile_stored_payloads_are_refused_before_any_job_exists(string $payload): void
    {
        // Arrange
        $codec = new PayloadCodec();

        // Act & Assert
        $this->expectException(InvalidPayload::class);
        $codec->parse($payload, WelcomeJob::class);
    }

    public function test_unknown_job_names_are_rejected_without_touching_the_autoloader(): void
    {
        // Arrange
        $loaded = [];
        spl_autoload_register($recorder = static function (string $class) use (&$loaded): void {
            $loaded[] = $class;
        }, true, true);
        $rejected = 0;

        try {
            foreach ($this->registries() as $registry) {
                foreach (['Evil\\Payload', 'stdClass', "Trunk\\Tests\\Fixtures\\Queue\\WelcomeJob\0", '../../etc/passwd', ''] as $name) {
                    // Act
                    try {
                        $registry->definition($name);
                    } catch (UnknownJob $e) {
                        ++$rejected;
                        self::assertStringNotContainsString("\0", $e->getMessage());
                    }
                }
            }
        } finally {
            spl_autoload_unregister($recorder);
        }

        // Assert
        self::assertSame(10, $rejected);
        self::assertSame([], array_values(array_filter($loaded, static fn(string $c): bool => str_contains($c, 'Evil') || str_contains($c, 'passwd'))));
    }

    public function test_encoding_rejects_unsafe_data_and_oversized_payloads(): void
    {
        // Arrange
        $codec = new PayloadCodec(200);
        $rejected = 0;
        $tooDeep = 'x';
        for ($i = 0; $i < 12; ++$i) {
            $tooDeep = [$tooDeep];
        }

        foreach ($this->registries() as $registry) {
            $definition = $registry->definition(WelcomeJob::class);

            foreach ([
                new WelcomeJob(1, tags: ['o' => new stdClass()]),
                new WelcomeJob(1, tags: ['f' => static fn() => 1]),
                new WelcomeJob(1, tags: $tooDeep),
                new WelcomeJob(1, tags: ['nan' => NAN]),
            ] as $job) {
                // Act
                try {
                    $codec->encode($definition->encode($job), WelcomeJob::class);
                } catch (InvalidPayload) {
                    ++$rejected;
                }
            }
        }

        try {
            $codec->encode(['big' => str_repeat('x', 500)], WelcomeJob::class);
        } catch (InvalidPayload $e) {
            ++$rejected;
            self::assertStringContainsString('byte limit', $e->getMessage());
        }

        // Assert
        self::assertSame(9, $rejected);
    }

    public function test_the_generated_file_is_deterministic_and_free_of_dynamic_code(): void
    {
        // Arrange
        $metadata = new JobMetadataFactory()->fromClasses([WelcomeJob::class, FailingJob::class]);
        $generator = new JobCodeGenerator();

        // Act
        $source = $generator->generate($metadata);

        // Assert
        self::assertSame($source, $generator->generate($metadata));
        foreach (['eval(', 'unserialize', 'Reflection', 'class_exists', 'new $'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
        self::assertStringContainsString("\$c->get('Trunk\\\\Tests\\\\Fixtures\\\\Queue\\\\Recorder')", $source);
    }

    /**
     * @return array<string, JobRegistry>
     */
    private function registries(): array
    {
        $classes = [WelcomeJob::class, FailingJob::class];
        file_put_contents($this->directory . '/queue.php', new JobCodeGenerator()->generate(new JobMetadataFactory()->fromClasses($classes)));

        return ['development' => new DevelopmentJobRegistry($classes), 'compiled' => JobArtifact::load($this->directory . '/queue.php')];
    }
}

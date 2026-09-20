<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use PHPUnit\Framework\TestCase;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Validation\Node;
use Trunk\Tests\Fixtures\Validation\RegisterRequest;
use Trunk\Tests\Fixtures\Validation\SearchRequest;
use Trunk\Validation\Compiler\CodeGenerator;
use Trunk\Validation\Compiler\CompiledValidation;
use Trunk\Validation\Compiler\PlanBuilder;
use Trunk\Validation\Compiler\ReflectionPlans;
use Trunk\Validation\Context;
use Trunk\Validation\Rules\Email;
use Trunk\Validation\Rules\Url;
use Trunk\Validation\Source;
use Trunk\Validation\Validator;

/**
 * Hostile input against the validator: it must only ever answer "valid object" or "these fields", never
 * throw, never echo what it was given, and never do unbounded work.
 */
final class ValidationSecurityTest extends TestCase
{
    private const string MARKER = 'ZQX7-MARKER';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-validation-sec-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    public function test_random_hostile_input_never_throws_never_echoes_and_agrees_between_development_and_compiled(): void
    {
        // Arrange
        mt_srand(20260920);
        $file = $this->directory . '/validation.php';
        file_put_contents($file, new CodeGenerator()->generate(new PlanBuilder()->all([RegisterRequest::class, SearchRequest::class, Node::class])));
        $compiled = require $file;
        self::assertInstanceOf(CompiledValidation::class, $compiled);
        $development = new Validator(new ReflectionPlans());
        $production = new Validator($compiled);

        for ($i = 0; $i < 600; $i++) {
            $input = $this->randomInput(4);

            foreach ([RegisterRequest::class, SearchRequest::class, Node::class] as $class) {
                foreach ([Source::Json, Source::Form, Source::Query] as $source) {
                    // Act
                    $a = $development->check($class, $input, $source);
                    $b = $production->check($class, $input, $source);

                    // Assert
                    self::assertEquals($a, $b);
                    self::assertLessThanOrEqual(100, \count($a->errors->toArray()));
                    self::assertStringNotContainsString(self::MARKER, json_encode($a->errors->toArray(), \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
                }
            }
        }
    }

    public function test_a_recursion_bomb_stops_at_the_depth_limit_without_exhausting_the_stack(): void
    {
        // Arrange
        $input = ['name' => 'x'];
        $cursor = &$input;

        for ($i = 0; $i < 5000; $i++) {
            $cursor['child'] = ['name' => 'x'];
            $cursor = &$cursor['child'];
        }

        unset($cursor);

        // Act
        $result = new Validator(new ReflectionPlans())->check(Node::class, $input);

        // Assert
        self::assertFalse($result->isValid());
        self::assertLessThanOrEqual(100, \count($result->errors->toArray()));
    }

    public function test_large_adversarial_strings_are_handled_in_bounded_time(): void
    {
        // Arrange
        $email = new Email();
        $url = new Url();
        $context = new Context('x');
        $inputs = [str_repeat('a', 1_000_000), str_repeat('a@', 500_000), str_repeat('a.', 500_000) . '@', 'http://' . str_repeat('a', 1_000_000), str_repeat("\xC3\xA9", 500_000)];
        $start = hrtime(true);

        // Act
        foreach ($inputs as $value) {
            self::assertNotNull($email->check($value, $context));
            self::assertNotNull($url->check($value, $context));
        }

        // Assert
        self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9, 'oversized input must be rejected without heavy work');
    }

    public function test_the_input_offered_back_after_a_failure_is_bounded_and_never_contains_sensitive_fields(): void
    {
        // Arrange
        $input = ['email' => str_repeat('e', 100_000), 'password' => self::MARKER, 'passwordConfirmation' => self::MARKER . 'x'];

        // Act
        $old = new Validator(new ReflectionPlans())->check(RegisterRequest::class, $input)->old;

        // Assert
        self::assertIsString($old['email'] ?? null);
        self::assertSame(1000, \strlen($old['email']));
        self::assertStringNotContainsString(self::MARKER, json_encode($old, \JSON_THROW_ON_ERROR));
    }

    public function test_the_regex_rules_refuse_a_trailing_newline_that_a_dollar_anchor_would_allow(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());
        $input = ['email' => "ada@example.com\n", 'password' => 'correct horse battery', 'passwordConfirmation' => 'correct horse battery'];

        // Act
        $errors = $validator->check(RegisterRequest::class, $input)->errors->toArray();

        // Assert
        self::assertArrayHasKey('email', $errors);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function randomInput(int $depth): array
    {
        $keys = ['email', 'password', 'passwordConfirmation', 'age', 'tags', 'address', 'shipTo', 'plan', 'newsletter', 'company', 'page', 'q', 'archived', 'name', 'child', 'street', 'city', 0, '', "a\0b"];
        $input = [];

        for ($n = mt_rand(0, 8); $n > 0; $n--) {
            $input[$keys[mt_rand(0, \count($keys) - 1)]] = $this->randomValue($depth);
        }

        return $input;
    }

    private function randomValue(int $depth): mixed
    {
        return match (mt_rand(0, 13)) {
            0 => null,
            1 => mt_rand() % 2 === 0,
            2 => mt_rand(),
            3 => \PHP_INT_MAX,
            4 => mt_rand() / 7,
            5 => \NAN,
            6 => self::MARKER . random_int(0, 99),
            7 => "\xB1\x31" . self::MARKER,
            8 => "a\0" . self::MARKER,
            9 => (string) mt_rand(-1000, 1000),
            10 => str_repeat('x', mt_rand(0, 300)),
            11 => $depth > 0 ? $this->randomInput($depth - 1) : [],
            12 => $depth > 0 ? array_map(fn() => $this->randomValue($depth - 1), range(0, mt_rand(0, 4))) : [],
            default => ['1', 'on', 'true', 'pro', 'free', 'ada@example.com'][mt_rand(0, 5)],
        };
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Validation\RegisterRequest;
use Trunk\Tests\Fixtures\Validation\SearchRequest;
use Trunk\Tests\Fixtures\Validation\Seats;
use Trunk\Validation\Compiler\CodeGenerator;
use Trunk\Validation\Compiler\CompiledValidation;
use Trunk\Validation\Compiler\Exporter;
use Trunk\Validation\Compiler\PlanBuilder;
use Trunk\Validation\Compiler\ReflectionPlans;
use Trunk\Validation\Rules\Pattern;
use Trunk\Validation\Source;
use Trunk\Validation\Validator;

final class CompiledParityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-validation-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    /**
     * @param class-string            $class
     * @param array<array-key, mixed> $input
     */
    #[DataProvider('inputs')]
    public function test_the_generated_plans_behave_exactly_like_the_development_plans(string $class, array $input, Source $source): void
    {
        // Arrange
        $file = $this->directory . '/validation.php';
        file_put_contents($file, new CodeGenerator()->generate(new PlanBuilder()->all([RegisterRequest::class, SearchRequest::class, Seats::class])));
        $compiled = require $file;
        self::assertInstanceOf(CompiledValidation::class, $compiled);

        // Act
        $expected = new Validator(new ReflectionPlans())->check($class, $input, $source);
        $actual = new Validator($compiled)->check($class, $input, $source);

        // Assert
        self::assertEquals($expected, $actual);
    }

    /**
     * @return iterable<string, array{class-string, array<array-key, mixed>, Source}>
     */
    public static function inputs(): iterable
    {
        $valid = ['email' => 'ada@example.com', 'password' => 'correct horse battery', 'passwordConfirmation' => 'correct horse battery'];

        yield 'valid' => [RegisterRequest::class, $valid, Source::Json];
        yield 'valid full' => [RegisterRequest::class, $valid + ['age' => 30, 'tags' => ['a'], 'plan' => 'pro', 'company' => 'Acme', 'address' => ['street' => 's', 'city' => 'c'], 'shipTo' => [['street' => 's', 'city' => 'c']]], Source::Json];
        yield 'empty' => [RegisterRequest::class, [], Source::Json];
        yield 'everything wrong' => [RegisterRequest::class, ['email' => 'x', 'password' => 'p', 'passwordConfirmation' => 'q', 'age' => 'x', 'tags' => 'x', 'address' => 5, 'shipTo' => [1], 'plan' => [], 'newsletter' => 'maybe'], Source::Json];
        yield 'form text' => [RegisterRequest::class, $valid + ['age' => '30', 'newsletter' => 'on'], Source::Form];
        yield 'query' => [SearchRequest::class, ['page' => '3', 'q' => 'ada', 'archived' => 'true'], Source::Query];
        yield 'custom rule ok' => [Seats::class, ['count' => 4], Source::Json];
        yield 'custom rule fails' => [Seats::class, ['count' => 3], Source::Json];
        yield 'query bad' => [SearchRequest::class, ['page' => '0', 'q' => str_repeat('x', 51)], Source::Query];
    }

    public function test_hostile_strings_in_rules_and_defaults_cannot_change_the_generated_code(): void
    {
        // Arrange
        $hostile = "');system('id');//" . '"$x{${`id`}}' . "\n?>";
        $rule = new Pattern('/^a$/D', $hostile);

        // Act
        $code = 'return ' . Exporter::export($rule) . ';';
        $rebuilt = eval_free($code, $this->directory);

        // Assert
        self::assertEquals($rule, $rebuilt);
    }

    public function test_a_rule_whose_constructor_values_are_not_public_properties_cannot_be_compiled(): void
    {
        // Arrange
        $rule = new class ('secret') {
            public function __construct(private readonly string $hidden) {}

            public function hidden(): string
            {
                return $this->hidden;
            }
        };

        // Act
        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('must be a public property');
        Exporter::export($rule);
    }
}

/**
 * Loads generated source by writing it to a file and requiring it, the way the framework does (no eval).
 */
function eval_free(string $code, string $directory): mixed
{
    $file = $directory . '/snippet.php';
    file_put_contents($file, "<?php\n" . $code);

    return require $file;
}

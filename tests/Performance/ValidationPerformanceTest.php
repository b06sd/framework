<?php

declare(strict_types=1);

namespace Trunk\Tests\Performance;

use PHPUnit\Framework\TestCase;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Validation\RegisterRequest;
use Trunk\Validation\Compiler\CodeGenerator;
use Trunk\Validation\Compiler\CompiledValidation;
use Trunk\Validation\Compiler\PlanBuilder;
use Trunk\Validation\Compiler\ReflectionPlans;
use Trunk\Validation\Validator;

/**
 * What validation costs per request (printed to stderr; the assertions are deliberately loose).
 */
final class ValidationPerformanceTest extends TestCase
{
    public function test_cost_of_validating_one_registration_in_development_and_compiled(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . '/trunk-validation-perf-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/validation.php', new CodeGenerator()->generate(new PlanBuilder()->all([RegisterRequest::class])));
        $compiled = require $directory . '/validation.php';
        self::assertInstanceOf(CompiledValidation::class, $compiled);
        $valid = ['email' => 'ada@example.com', 'password' => 'correct horse battery', 'passwordConfirmation' => 'correct horse battery', 'age' => 36, 'tags' => ['a', 'b'], 'address' => ['street' => '1 Main', 'city' => 'Lagos']];
        $invalid = ['email' => 'nope', 'password' => 'x', 'passwordConfirmation' => 'y', 'age' => 3, 'plan' => 'gold'];
        $report = [];

        foreach (['development' => new Validator(new ReflectionPlans()), 'compiled' => new Validator($compiled)] as $mode => $validator) {
            foreach (['valid' => $valid, 'invalid' => $invalid] as $label => $input) {
                $validator->check(RegisterRequest::class, $input);
                $samples = [];

                for ($round = 0; $round < 7; ++$round) {
                    $start = hrtime(true);

                    for ($i = 0; $i < 2000; ++$i) {
                        $validator->check(RegisterRequest::class, $input);
                    }

                    $samples[] = (hrtime(true) - $start) / 2000 / 1000;
                }

                sort($samples);
                $report[$mode . ' ' . $label] = $samples[3];
            }
        }

        // Act
        $start = hrtime(true);

        for ($i = 0; $i < 20; ++$i) {
            new PlanBuilder()->plan(RegisterRequest::class);
        }

        $planMicros = (hrtime(true) - $start) / 20 / 1000;
        new Directory()->remove($directory);

        // Assert (report)
        foreach ($report as $name => $micros) {
            fwrite(\STDERR, \sprintf("validation %-20s %7.2f us per request\n", $name, $micros));
            self::assertLessThan(500.0, $micros, $name . ' must stay far below a millisecond');
        }

        fwrite(\STDERR, \sprintf("validation reading one request class by reflection (development, first use only): %.1f us\n", $planMicros));
        self::assertLessThan(100.0, $report['compiled valid'], 'validating a normal request stays under 100 us');
    }
}

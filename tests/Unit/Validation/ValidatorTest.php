<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Error\ValidationException;
use Trunk\Tests\Fixtures\Validation\Address;
use Trunk\Tests\Fixtures\Validation\Plan;
use Trunk\Tests\Fixtures\Validation\RegisterRequest;
use Trunk\Tests\Fixtures\Validation\SearchRequest;
use Trunk\Validation\Compiler\ReflectionPlans;
use Trunk\Validation\Source;
use Trunk\Validation\Validator;

final class ValidatorTest extends TestCase
{
    private const array VALID = ['email' => 'ada@example.com', 'password' => 'correct horse battery', 'passwordConfirmation' => 'correct horse battery'];

    public function test_valid_input_becomes_a_request_object_with_defaults_for_what_was_left_out(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());

        // Act
        $request = $validator->validate(RegisterRequest::class, self::VALID);

        // Assert
        self::assertSame('ada@example.com', $request->email);
        self::assertNull($request->age);
        self::assertSame([], $request->tags);
        self::assertSame(Plan::Free, $request->plan);
        self::assertFalse($request->newsletter);
    }

    public function test_nested_objects_lists_enums_and_lists_of_objects_are_built(): void
    {
        // Arrange
        $input = self::VALID + [
            'age' => 30, 'tags' => ['a', 'b'], 'plan' => 'pro', 'company' => 'Acme',
            'address' => ['street' => '1 Main', 'city' => 'Lagos'],
            'shipTo' => [['street' => '2 Side', 'city' => 'Abuja']],
        ];

        // Act
        $request = new Validator(new ReflectionPlans())->validate(RegisterRequest::class, $input);

        // Assert
        self::assertSame(Plan::Pro, $request->plan);
        self::assertEquals(new Address('1 Main', 'Lagos'), $request->address);
        self::assertEquals([new Address('2 Side', 'Abuja')], $request->shipTo);
    }

    public function test_every_failing_field_is_reported_once_with_a_path_and_no_submitted_value(): void
    {
        // Arrange
        $secret = 'ZZ-do-not-echo-ZZ';
        $input = ['email' => $secret, 'password' => 'short', 'passwordConfirmation' => 'other', 'age' => 3, 'tags' => ['ok', str_repeat('x', 11)], 'address' => ['street' => 'x'], 'plan' => $secret];

        // Act
        $result = new Validator(new ReflectionPlans())->check(RegisterRequest::class, $input);

        // Assert
        self::assertFalse($result->isValid());
        self::assertSame(['email', 'password', 'passwordConfirmation', 'age', 'tags', 'address.city', 'plan'], array_keys($result->errors->toArray()));
        self::assertStringNotContainsString($secret, json_encode($result->errors->toArray(), \JSON_THROW_ON_ERROR));
        self::assertSame('Item 2: Must be at most 10 characters.', $result->errors->first('tags'));
    }

    public function test_a_missing_required_field_and_a_required_if_are_reported(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());

        // Act
        $errors = $validator->check(RegisterRequest::class, ['plan' => 'pro'])->errors->toArray();

        // Assert
        self::assertSame('required', $errors['email']['rule']);
        self::assertSame('required_if', $errors['company']['rule']);
    }

    public function test_json_is_strict_about_types_but_form_input_is_read_from_text(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());
        $text = self::VALID + ['age' => '30', 'newsletter' => 'on'];

        // Act
        $json = $validator->check(RegisterRequest::class, $text, Source::Json);
        $form = $validator->validate(RegisterRequest::class, $text, Source::Form);

        // Assert
        self::assertSame('type', $json->errors->toArray()['age']['rule']);
        self::assertSame(30, $form->age);
        self::assertTrue($form->newsletter);
    }

    #[DataProvider('badText')]
    public function test_form_numbers_and_booleans_are_parsed_strictly(string $age): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());

        // Act
        $result = $validator->check(RegisterRequest::class, self::VALID + ['age' => $age], Source::Form);

        // Assert
        self::assertFalse($result->isValid());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badText(): iterable
    {
        yield 'hex' => ['0x1F'];
        yield 'exponent' => ['1e2'];
        yield 'spaces' => [' 30'];
        yield 'huge' => ['99999999999999999999'];
        yield 'plus' => ['+30'];
        yield 'decimal for int' => ['30.5'];
    }

    public function test_an_empty_form_field_counts_as_absent_for_numbers_but_is_a_value_for_text(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());

        // Act
        $request = $validator->validate(SearchRequest::class, ['page' => '', 'q' => ''], Source::Query);

        // Assert
        self::assertSame(1, $request->page);
        self::assertSame('', $request->q);
    }

    public function test_sensitive_fields_are_never_offered_for_redisplay(): void
    {
        // Arrange
        $input = ['email' => 'not-an-email', 'password' => 'hunter2', 'passwordConfirmation' => 'hunter2'];

        // Act
        $old = new Validator(new ReflectionPlans())->check(RegisterRequest::class, $input)->old;

        // Assert
        self::assertSame(['email' => 'not-an-email'], $old);
    }

    public function test_validate_throws_a_public_422_error_with_field_details(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());

        // Act
        try {
            $validator->validate(RegisterRequest::class, []);
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            // Assert
            self::assertSame(422, $e->statusCode());
            self::assertSame('VALIDATION_FAILED', $e->errorCode());
            self::assertSame('required', $e->rules()['email']);
            self::assertSame('This field is required.', $e->first('email'));
        }
    }

    public function test_the_first_error_of_a_field_wins_and_later_rules_are_not_run(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());

        // Act
        $errors = $validator->check(RegisterRequest::class, ['email' => ''] + self::VALID)->errors;

        // Assert
        self::assertSame('required', $errors->toArray()['email']['rule']);
    }

    public function test_nesting_lists_and_error_counts_are_bounded(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());
        $tooMany = self::VALID + ['tags' => array_fill(0, 1001, 'a')];
        $manyBad = array_map(static fn(int $i): array => ['street' => ''], range(1, 500));

        // Act
        $list = $validator->check(RegisterRequest::class, $tooMany);
        $bad = $validator->check(RegisterRequest::class, self::VALID + ['shipTo' => $manyBad]);

        // Assert
        self::assertSame('max_items', $list->errors->toArray()['tags']['rule']);
        self::assertLessThanOrEqual(100, \count($bad->errors->toArray()));
    }

    public function test_invalid_utf8_and_nul_bytes_are_refused_for_every_text_field(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());

        // Act
        $utf8 = $validator->check(RegisterRequest::class, self::VALID + ['company' => "\xB1\x31"]);
        $nul = $validator->check(RegisterRequest::class, self::VALID + ['company' => "a\0b"]);

        // Assert
        self::assertSame('type', $utf8->errors->toArray()['company']['rule']);
        self::assertFalse($nul->isValid());
    }

    public function test_unknown_input_keys_are_ignored_so_nothing_undeclared_can_be_set(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());

        // Act
        $request = $validator->validate(RegisterRequest::class, self::VALID + ['isAdmin' => true, '__construct' => 'x']);

        // Assert
        self::assertArrayNotHasKey('isAdmin', get_object_vars($request));
    }

    public function test_a_value_of_the_wrong_type_for_an_enum_is_a_field_error_not_a_server_error(): void
    {
        // Arrange
        $validator = new Validator(new ReflectionPlans());

        // Act
        $int = $validator->check(RegisterRequest::class, self::VALID + ['plan' => 1]);
        $list = $validator->check(RegisterRequest::class, self::VALID + ['plan' => ['pro']]);
        $float = $validator->check(RegisterRequest::class, self::VALID + ['plan' => 1.5]);

        // Assert
        foreach ([$int, $list, $float] as $result) {
            self::assertSame('one_of', $result->errors->toArray()['plan']['rule']);
        }
    }
}

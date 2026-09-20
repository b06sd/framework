<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Error;

use PHPUnit\Framework\TestCase;
use Trunk\Error\ErrorCode;
use Trunk\Error\ValidationException;

final class ValidationExceptionTest extends TestCase
{
    public function test_it_is_a_422_listing_messages_per_field(): void
    {
        // Arrange
        $exception = new ValidationException(['email' => ['is required', 'is too short'], 'age' => ['must be a number']]);

        // Act
        $details = $exception->details();

        // Assert
        self::assertSame(422, $exception->statusCode());
        self::assertSame(ErrorCode::ValidationFailed->value, $exception->errorCode());
        self::assertSame(['fields' => ['email' => ['is required', 'is too short'], 'age' => ['must be a number']]], $details);
        self::assertSame('is required', $exception->first('email'));
        self::assertNull($exception->first('name'));
        self::assertSame(['email' => 'is required', 'age' => 'must be a number'], $exception->firstMessages());
    }

    public function test_rule_codes_appear_in_the_details_only_when_they_are_known(): void
    {
        // Arrange
        $with = new ValidationException(['email' => ['bad']], rules: ['email' => 'email']);
        $without = new ValidationException(['email' => ['bad']]);

        // Act and assert
        self::assertSame(['fields' => ['email' => ['bad']], 'rules' => ['email' => 'email']], $with->details());
        self::assertArrayNotHasKey('rules', $without->details());
    }

    public function test_field_is_the_short_way_to_reject_one_field(): void
    {
        // Arrange
        $exception = ValidationException::field('email', 'Already registered.');

        // Act and assert
        self::assertSame(['email' => ['Already registered.']], $exception->errors());
        self::assertSame(['email' => 'invalid'], $exception->rules());
        self::assertSame([], $exception->old());
    }

    public function test_old_input_is_carried_for_redisplay_but_never_put_in_the_response(): void
    {
        // Arrange
        $exception = new ValidationException(['email' => ['bad']], old: ['email' => 'ada@']);

        // Act and assert
        self::assertSame(['email' => 'ada@'], $exception->old());
        self::assertStringNotContainsString('ada@', json_encode($exception->details(), \JSON_THROW_ON_ERROR));
    }
}

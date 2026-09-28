<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Testing;

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Http\Message\Response;
use Trunk\Http\Stream\Stream;
use Trunk\Testing\TestResponse;

final class TestResponseTest extends TestCase
{
    public function test_status_assertions_pass_and_fail_with_a_clear_message(): void
    {
        // Arrange
        $ok = new TestResponse(new Response(200));
        $notFound = new TestResponse(new Response(404));

        // Act & Assert
        self::assertSame($ok, $ok->assertOk());
        self::assertSame($ok, $ok->assertStatus(200));

        try {
            $notFound->assertOk();
            self::fail('expected a failed assertion');
        } catch (ExpectationFailedException $e) {
            self::assertStringContainsString('Expected status 200, got 404', $e->getMessage());
        }
    }

    public function test_assert_redirect_checks_the_status_and_optionally_the_location(): void
    {
        // Arrange
        $response = new TestResponse(new Response(302, ['Location' => '/account']));

        // Act & Assert
        $response->assertRedirect();
        $response->assertRedirect('/account');

        try {
            $response->assertRedirect('/elsewhere');
            self::fail('expected a failed assertion');
        } catch (ExpectationFailedException) {
            $this->addToAssertionCount(1);
        }

        try {
            new TestResponse(new Response(200))->assertRedirect();
            self::fail('expected a failed assertion');
        } catch (ExpectationFailedException $e) {
            self::assertStringContainsString('Expected a redirect status, got 200', $e->getMessage());
        }
    }

    public function test_header_assertions(): void
    {
        // Arrange
        $response = new TestResponse(new Response(200, ['X-Request-Id' => 'abc']));

        // Act & Assert
        $response->assertHeader('X-Request-Id');
        $response->assertHeader('X-Request-Id', 'abc');
        $response->assertHeaderMissing('X-Missing');

        try {
            $response->assertHeader('X-Request-Id', 'wrong');
            self::fail('expected a failed assertion');
        } catch (ExpectationFailedException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_see_and_dont_see(): void
    {
        // Arrange
        $response = new TestResponse(new Response(200, [], Stream::fromString('<h1>Hello</h1>')));

        // Act & Assert
        $response->assertSee('<h1>Hello</h1>');
        $response->assertDontSee('Goodbye');

        try {
            $response->assertSee('Goodbye');
            self::fail('expected a failed assertion');
        } catch (ExpectationFailedException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_json_helpers(): void
    {
        // Arrange
        $response = new TestResponse(new Response(200, ['Content-Type' => 'application/json'], Stream::fromString('{"name":"Ada","admin":true}')));
        $notJson = new TestResponse(new Response(200, [], Stream::fromString('not json')));

        // Act & Assert
        self::assertSame(['name' => 'Ada', 'admin' => true], $response->json());
        self::assertSame([], $notJson->json());
        $response->assertJson(['name' => 'Ada']);

        try {
            $response->assertJson(['name' => 'Grace']);
            self::fail('expected a failed assertion');
        } catch (ExpectationFailedException) {
            $this->addToAssertionCount(1);
        }

        try {
            $response->assertJson(['missing' => 1]);
            self::fail('expected a failed assertion');
        } catch (ExpectationFailedException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_csrf_token_is_read_from_an_html_input_regardless_of_attribute_order(): void
    {
        // Arrange
        $normalOrder = new TestResponse(new Response(200, [], Stream::fromString('<input type="hidden" name="_csrf" value="abc123">')));
        $reversedOrder = new TestResponse(new Response(200, [], Stream::fromString('<input value="xyz789" name="_csrf" type="hidden">')));
        $customField = new TestResponse(new Response(200, [], Stream::fromString('<input name="token" value="def456">')));

        // Act & Assert
        self::assertSame('abc123', $normalOrder->csrfToken());
        self::assertSame('xyz789', $reversedOrder->csrfToken());
        self::assertSame('def456', $customField->csrfToken('token'));
    }

    public function test_csrf_token_is_read_from_a_json_body_when_the_response_is_json(): void
    {
        // Arrange
        $response = new TestResponse(new Response(200, ['Content-Type' => 'application/json'], Stream::fromString('{"_csrf":"the-token"}')));

        // Act & Assert
        self::assertSame('the-token', $response->csrfToken());
    }

    public function test_csrf_token_throws_a_clear_error_when_the_field_is_not_present(): void
    {
        // Arrange
        $response = new TestResponse(new Response(200, [], Stream::fromString('<p>no form here</p>')));

        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No "_csrf" input found');
        $response->csrfToken();
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Testing;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A response from `TestClient`, with fluent PHPUnit assertions. A failed assertion is a normal
 * PHPUnit failure (with a diff, in the usual place); nothing here is a custom test runner.
 *
 * @api
 */
final readonly class TestResponse
{
    public function __construct(private ResponseInterface $response) {}

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * The decoded JSON body, or an empty array when it is not a JSON object or array.
     *
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body(), true, 32);

        return \is_array($decoded) ? $decoded : [];
    }

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function response(): ResponseInterface
    {
        return $this->response;
    }

    public function assertStatus(int $expected): static
    {
        Assert::assertSame($expected, $this->status(), \sprintf('Expected status %d, got %d. Body: %s', $expected, $this->status(), $this->body()));

        return $this;
    }

    public function assertOk(): static
    {
        return $this->assertStatus(200);
    }

    public function assertRedirect(?string $to = null): static
    {
        Assert::assertContains($this->status(), [301, 302, 303, 307, 308], 'Expected a redirect status, got ' . $this->status() . '.');

        if ($to !== null) {
            Assert::assertSame($to, $this->header('Location'));
        }

        return $this;
    }

    public function assertHeader(string $name, ?string $value = null): static
    {
        Assert::assertTrue($this->response->hasHeader($name), \sprintf('Expected a "%s" header.', $name));

        if ($value !== null) {
            Assert::assertSame($value, $this->header($name));
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): static
    {
        Assert::assertFalse($this->response->hasHeader($name), \sprintf('Did not expect a "%s" header.', $name));

        return $this;
    }

    public function assertSee(string $needle): static
    {
        Assert::assertStringContainsString($needle, $this->body());

        return $this;
    }

    public function assertDontSee(string $needle): static
    {
        Assert::assertStringNotContainsString($needle, $this->body());

        return $this;
    }

    /**
     * Every key in `$subset` matches the top-level JSON body (extra keys in the body are ignored).
     *
     * @param array<string, mixed> $subset
     */
    public function assertJson(array $subset): static
    {
        $actual = $this->json();

        foreach ($subset as $key => $value) {
            Assert::assertArrayHasKey($key, $actual, \sprintf('Expected the JSON body to have "%s". Body: %s', $key, $this->body()));
            Assert::assertSame($value, $actual[$key], \sprintf('JSON key "%s" did not match.', $key));
        }

        return $this;
    }

    /**
     * Reads a CSRF (or any hidden-input) value the same way a browser would: from the page it just
     * fetched, before submitting a form. Works for an HTML page (`<input type="hidden" name="_csrf"
     * value="...">`, attribute order does not matter) or a JSON body with that key.
     *
     * @throws RuntimeException when the field is not present
     */
    public function csrfToken(string $field = '_csrf'): string
    {
        if (str_contains($this->header('Content-Type'), 'json')) {
            $value = $this->json()[$field] ?? null;

            return \is_string($value) ? $value : throw new RuntimeException(\sprintf('The JSON body has no "%s" field to read a token from.', $field));
        }

        $body = $this->body();

        if (preg_match('/<input\b[^>]*\bname=(["\'])' . preg_quote($field, '/') . '\1[^>]*>/i', $body, $tag) === 1
            && preg_match('/\bvalue=(["\'])(.*?)\1/i', $tag[0], $value) === 1) {
            return html_entity_decode($value[2], \ENT_QUOTES);
        }

        throw new RuntimeException(\sprintf('No "%s" input found in the response to read a token from.', $field));
    }
}

<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Validation;

use Closure;
use PHPUnit\Framework\TestCase;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Stream\Stream;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Modules\ValidationWebModule;
use Trunk\Tests\Fixtures\Validation\RegisterRequest;
use Trunk\Tests\Fixtures\Validation\SearchRequest;
use Trunk\Tests\Support\KernelHarness;
use Trunk\Tests\Support\ViewHarness;
use Trunk\Validation\ValidationModule;

final class RequestValidationTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery';

    /** @var list<KernelHarness> */
    private array $harnesses = [];

    private string $build;

    protected function setUp(): void
    {
        $this->build = sys_get_temp_dir() . '/trunk-validation-build-' . bin2hex(random_bytes(4));
        mkdir($this->build);
    }

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->cleanUp();
        }

        new Directory()->remove($this->build);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'development' => [false];
        yield 'compiled' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_valid_json_body_reaches_the_controller_as_a_typed_object(bool $compiled): void
    {
        // Arrange
        $kernel = $this->kernel($compiled);

        // Act
        [$status, , $body] = $this->post($kernel, ['email' => 'ada@example.com', 'password' => self::PASSWORD, 'passwordConfirmation' => self::PASSWORD, 'age' => 36]);

        // Assert
        self::assertSame(201, $status);
        self::assertSame('{"email":"ada@example.com","plan":"free","age":36}', $body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_invalid_input_is_a_422_listing_each_field_without_echoing_what_was_sent(bool $compiled): void
    {
        // Arrange
        $kernel = $this->kernel($compiled);
        $secret = 'Zx9-do-not-echo';

        // Act
        [$status, $type, $body] = $this->post($kernel, ['email' => $secret, 'password' => 'short', 'passwordConfirmation' => 'other', 'age' => 3]);
        $json = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);

        // Assert
        self::assertSame(422, $status);
        self::assertSame('application/json; charset=utf-8', $type);
        self::assertSame('VALIDATION_FAILED', self::dig($json, 'error', 'code'));
        $fields = self::dig($json, 'error', 'details', 'fields');
        self::assertIsArray($fields);
        self::assertSame(['email', 'password', 'passwordConfirmation', 'age'], array_keys($fields));
        self::assertSame('email', self::dig($json, 'error', 'details', 'rules', 'email'));
        self::assertSame(['Must be a valid email address.'], self::dig($fields, 'email'));
        self::assertNotSame('', self::dig($json, 'error', 'requestId'));
        self::assertStringNotContainsString($secret, $body);
        self::assertStringNotContainsString('short', $body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_rule_only_the_application_can_check_uses_the_same_error_shape(bool $compiled): void
    {
        // Arrange
        $kernel = $this->kernel($compiled);

        // Act
        [$status, , $body] = $this->post($kernel, ['email' => 'taken@example.com', 'password' => self::PASSWORD, 'passwordConfirmation' => self::PASSWORD]);

        // Assert
        self::assertSame(422, $status);
        self::assertStringContainsString('"fields":{"email":["Already registered."]}', $body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_body_that_is_not_json_is_a_400_and_the_wrong_content_type_a_415(bool $compiled): void
    {
        // Arrange
        $kernel = $this->kernel($compiled);
        $json = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        // Act
        $broken = $kernel->handle(new ServerRequest('POST', 'http://trunk.dev/signup', $json, Stream::fromString('{"email":')));
        $array = $kernel->handle(new ServerRequest('POST', 'http://trunk.dev/signup', $json, Stream::fromString('"just a string"')));

        // Assert
        self::assertSame(400, $broken->getStatusCode());
        self::assertSame(400, $array->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_the_query_string_is_read_for_a_class_marked_from_query(bool $compiled): void
    {
        // Arrange
        $kernel = $this->kernel($compiled);

        // Act
        $ok = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/search?page=2&q=ada&archived=1')->withQueryParams(['page' => '2', 'q' => 'ada', 'archived' => '1']));
        $bad = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/search?page=abc', ['Accept' => 'application/json'])->withQueryParams(['page' => 'abc']));

        // Assert
        self::assertSame('{"page":2,"q":"ada","archived":true}', (string) $ok->getBody());
        self::assertSame(422, $bad->getStatusCode());
    }

    public function test_a_form_body_is_validated_from_the_parsed_body(): void
    {
        // Arrange
        $kernel = $this->kernel(false);
        $request = new ServerRequest('POST', 'http://trunk.dev/signup', ['Content-Type' => 'application/x-www-form-urlencoded'])
            ->withParsedBody(['email' => 'ada@example.com', 'password' => self::PASSWORD, 'passwordConfirmation' => self::PASSWORD, 'age' => '36', 'newsletter' => 'on']);

        // Act
        $response = $kernel->handle($request);

        // Assert
        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('"age":36', (string) $response->getBody());
    }

    public function test_the_build_writes_plans_for_the_listed_classes_and_reports_a_bad_one_as_a_build_error(): void
    {
        // Arrange
        $harness = $this->harness(false);
        $context = new BuildContext($harness->manifest(), new Runtime(Environment::Production, false, $this->build), new Configuration(['validation' => ['requests' => [RegisterRequest::class, SearchRequest::class]]]));
        $bad = new BuildContext($harness->manifest(), new Runtime(Environment::Production, false, $this->build), new Configuration(['validation' => ['requests' => ['App\\Requests\\Missing']]]));

        // Act
        $contribution = new ValidationModule()->plan($context);
        array_map(fn(Closure $write) => $write($this->build), $contribution->writers);

        // Assert
        self::assertFileExists($this->build . '/validation.php');
        self::assertStringContainsString('Generated by `trunk build`', (string) file_get_contents($this->build . '/validation.php'));
        $this->expectException(\Trunk\Compiler\Exception\CompilationException::class);
        $this->expectExceptionMessage('App\\Requests\\Missing does not exist');
        new ValidationModule()->plan($bad);
    }

    public function test_the_form_template_shown_in_the_guide_redisplays_safe_input_and_the_messages_escaped(): void
    {
        // Arrange
        $views = new ViewHarness(['signup' => "<input name=\"email\" value=\"{{ old.email }}\">\n<if test=\"errors.email\"><p class=\"error\">{{ errors.email }}</p></if>\n"]);
        $result = new \Trunk\Validation\Validator(new \Trunk\Validation\Compiler\ReflectionPlans())->check(RegisterRequest::class, ['email' => '"><script>x</script>', 'password' => 'p', 'passwordConfirmation' => 'p']);

        // Act
        $html = $views->render('signup', ['errors' => $result->errors->messages(), 'old' => $result->old]);
        $views->cleanUp();

        // Assert
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $html);
        self::assertStringContainsString('<p class="error">Must be a valid email address.</p>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{int, string, string}
     */
    private function post(HttpKernel $kernel, array $data): array
    {
        $response = $kernel->handle(new ServerRequest('POST', 'http://trunk.dev/signup', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], Stream::fromString(json_encode($data, \JSON_THROW_ON_ERROR))));

        return [$response->getStatusCode(), $response->getHeaderLine('Content-Type'), (string) $response->getBody()];
    }

    private function kernel(bool $compiled): HttpKernel
    {
        $harness = $this->harness($compiled);

        if (!$compiled) {
            return $harness->development();
        }

        $context = new BuildContext($harness->manifest(), new Runtime(Environment::Production, false, $this->build), new Configuration(['validation' => ['requests' => [RegisterRequest::class, SearchRequest::class]]]));
        array_map(fn(Closure $write) => $write($this->build), new ValidationModule()->plan($context)->writers);

        return $harness->compiled();
    }

    private function harness(bool $compiled): KernelHarness
    {
        $harness = new KernelHarness(modules: [HttpModule::class, ValidationModule::class, ValidationWebModule::class], configuration: ['validation' => ['mode' => $compiled ? 'compiled' : 'development', 'build' => $this->build]]);
        $this->harnesses[] = $harness;

        return $harness;
    }

    private static function dig(mixed $data, string ...$path): mixed
    {
        foreach ($path as $key) {
            $data = \is_array($data) ? ($data[$key] ?? null) : null;
        }

        return $data;
    }
}

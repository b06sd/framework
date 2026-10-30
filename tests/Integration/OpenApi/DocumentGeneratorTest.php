<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\OpenApi;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Http\HttpModule;
use Trunk\OpenApi\Exception\OpenApiException;
use Trunk\OpenApi\OpenApi;
use Trunk\OpenApi\OpenApiModule;
use Trunk\Tests\Fixtures\OpenApi\BrokenOpenApiModule;
use Trunk\Tests\Fixtures\OpenApi\OpenApiRoutesModule;
use Trunk\Tests\Fixtures\OpenApi\RequireApiKey;
use Trunk\Tests\Fixtures\OpenApi\TokenRoutesModule;
use Trunk\Validation\ValidationModule;

final class DocumentGeneratorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $document;

    protected function setUp(): void
    {
        $this->document = $this->generate([OpenApiRoutesModule::class, TokenRoutesModule::class]);
    }

    public function test_the_document_names_its_version_info_and_servers(): void
    {
        // Assert
        self::assertSame('3.1.0', $this->document['openapi']);
        self::assertSame(['title' => 'Shop', 'version' => '2.0.0'], $this->document['info']);
        self::assertSame([['url' => 'https://api.example.com']], $this->document['servers']);
    }

    public function test_patterns_become_openapi_paths_and_the_docs_own_routes_are_left_out(): void
    {
        // Act
        $paths = $this->keys('paths');

        // Assert
        self::assertSame(['/me/orders/{id}', '/orders', '/orders/preview', '/orders/{id}', '/pages', '/pages/{page}', '/register', '/shops/{shop}/orders'], $paths);
        self::assertSame(['delete', 'get'], $this->keys('paths', '/orders/{id}'));
    }

    public function test_a_query_class_becomes_query_parameters_with_its_rules(): void
    {
        // Act
        $operation = $this->array('paths', '/orders', 'get');

        // Assert
        self::assertSame('orders.index', $operation['operationId']);
        self::assertSame(['DocumentedOrder'], $operation['tags']);
        self::assertSame('List orders', $operation['summary']);
        self::assertSame(
            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 1]],
            $this->at('paths', '/orders', 'get', 'parameters', 0),
        );
        self::assertSame(['type' => ['string', 'null'], 'maxLength' => 50, 'default' => null], $this->at('paths', '/orders', 'get', 'parameters', 1, 'schema'));
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OrderView']], $this->at('paths', '/orders', 'get', 'responses', 200, 'content', 'application/json', 'schema'));
        self::assertSame(['$ref' => '#/components/responses/ValidationFailed'], $this->at('paths', '/orders', 'get', 'responses', 422));
    }

    public function test_a_body_class_becomes_the_request_body_next_to_path_parameters(): void
    {
        // Act
        $operation = $this->array('paths', '/shops/{shop}/orders', 'post');

        // Assert
        self::assertSame('documentedOrder.store', $operation['operationId']);
        self::assertSame('Place an order', $operation['summary']);
        self::assertSame('Stock is reserved at once; payment follows.', $operation['description']);
        self::assertSame([['name' => 'shop', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]], $operation['parameters']);
        self::assertSame(['application/json', 'application/x-www-form-urlencoded'], $this->keys('paths', '/shops/{shop}/orders', 'post', 'requestBody', 'content'));
        self::assertSame(['$ref' => '#/components/schemas/Order'], $this->at('paths', '/shops/{shop}/orders', 'post', 'requestBody', 'content', 'application/json', 'schema'));
        self::assertSame(['201', '404', '422'], array_map(\strval(...), $this->keys('paths', '/shops/{shop}/orders', 'post', 'responses')));
    }

    public function test_validation_rules_become_schema_keywords(): void
    {
        // Act
        $order = $this->array('components', 'schemas', 'Order');
        $register = $this->array('components', 'schemas', 'RegisterRequest', 'properties');

        // Assert
        self::assertSame(['type' => 'object', 'properties' => ['sku' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40], 'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'default' => 1]], 'required' => ['sku']], $order);
        self::assertSame(['type' => 'string', 'minLength' => 1, 'format' => 'email'], $register['email']);
        self::assertSame(['type' => 'string', 'minLength' => 12, 'format' => 'password'], $register['password']);
        self::assertSame('Must equal password.', $this->at('components', 'schemas', 'RegisterRequest', 'properties', 'passwordConfirmation', 'description'));
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10], 'maxItems' => 3, 'default' => []], $register['tags']);
        self::assertSame(['anyOf' => [['$ref' => '#/components/schemas/Address'], ['type' => 'null']], 'default' => null], $register['address']);
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Address'], 'maxItems' => 2, 'default' => []], $register['shipTo']);
        self::assertSame(['type' => 'string', 'enum' => ['free', 'pro'], 'default' => 'free'], $register['plan']);
        self::assertSame(['email', 'password', 'passwordConfirmation'], $this->at('components', 'schemas', 'RegisterRequest', 'required'));
        self::assertArrayHasKey('Address', $this->array('components', 'schemas'));
    }

    public function test_a_view_class_is_described_from_its_public_properties(): void
    {
        // Act
        $view = $this->array('components', 'schemas', 'OrderView');

        // Assert
        self::assertSame(['id', 'status', 'note', 'placedAt', 'lines', 'replaces'], $view['required']);
        self::assertSame([
            'id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => ['open', 'shipped']],
            'note' => ['type' => ['string', 'null']],
            'placedAt' => ['type' => 'string', 'format' => 'date-time'],
            'lines' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/LineView']],
            'replaces' => ['anyOf' => [['$ref' => '#/components/schemas/OrderView'], ['type' => 'null']]],
        ], $view['properties']);
        self::assertSame(['type' => 'string', 'format' => 'decimal'], $this->at('components', 'schemas', 'LineView', 'properties', 'price'));
    }

    public function test_token_middleware_becomes_a_security_requirement_with_a_401(): void
    {
        // Act
        $show = $this->array('paths', '/me/orders/{id}', 'get');

        // Assert
        self::assertSame([['apiToken' => []]], $show['security']);
        self::assertSame(['$ref' => '#/components/responses/Unauthenticated'], $this->at('paths', '/me/orders/{id}', 'get', 'responses', 401));
        self::assertSame('bearer', $this->at('components', 'securitySchemes', 'apiToken', 'scheme'));
        self::assertSame('trk_<id>.<secret>', $this->at('components', 'securitySchemes', 'apiToken', 'bearerFormat'));
        self::assertArrayNotHasKey('security', $this->array('paths', '/orders', 'get'));
    }

    public function test_an_applications_own_middleware_is_described_through_configuration(): void
    {
        // Assert
        self::assertSame([['apiKey' => []]], $this->at('paths', '/orders/{id}', 'get', 'security'));
        self::assertSame(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'], $this->at('components', 'securitySchemes', 'apiKey'));
        self::assertSame(['apiKey', 'apiToken'], $this->keys('components', 'securitySchemes'));
    }

    public function test_deprecated_handlers_and_undocumented_responses_are_marked(): void
    {
        // Assert
        self::assertTrue($this->at('paths', '/orders/{id}', 'delete', 'deprecated'));
        self::assertSame(['description' => 'Gone for good'], $this->at('paths', '/orders/{id}', 'delete', 'responses', 204));
        self::assertSame('Not documented yet: add #[Returns] to DocumentedOrderController::register().', $this->at('paths', '/register', 'post', 'responses', 'default', 'description'));
    }

    public function test_an_optional_parameter_is_listed_as_two_paths(): void
    {
        // Assert
        self::assertArrayNotHasKey('parameters', $this->array('paths', '/pages', 'get'));
        self::assertSame('page', $this->at('paths', '/pages/{page}', 'get', 'parameters', 0, 'name'));
        self::assertNotSame($this->at('paths', '/pages', 'get', 'operationId'), $this->at('paths', '/pages/{page}', 'get', 'operationId'));
    }

    public function test_a_class_used_as_both_input_and_output_gets_one_schema_each(): void
    {
        // Act
        $request = $this->at('paths', '/orders/preview', 'post', 'requestBody', 'content', 'application/json', 'schema');
        $response = $this->at('paths', '/orders/preview', 'post', 'responses', 200, 'content', 'application/json', 'schema');

        // Assert
        self::assertSame(['$ref' => '#/components/schemas/Order'], $request);
        self::assertSame(['$ref' => '#/components/schemas/OrderResponse'], $response);
        self::assertSame(['sku', 'quantity'], $this->at('components', 'schemas', 'OrderResponse', 'required'), 'every property is present in a response');
        self::assertSame(['sku'], $this->at('components', 'schemas', 'Order', 'required'));
    }

    public function test_the_document_is_deterministic_json(): void
    {
        // Act
        $first = OpenApi::encode($this->document);
        $second = OpenApi::encode($this->generate([OpenApiRoutesModule::class, TokenRoutesModule::class]));

        // Assert
        self::assertSame($first, $second);
        self::assertJson($first);
    }

    public function test_a_returns_class_that_does_not_exist_is_an_error_naming_the_route(): void
    {
        // Act & Assert
        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('Route /broken: Trunk\Tests\Fixtures\OpenApi\BrokenReturnsController::show() is #[Returns(200, Trunk\Tests\Fixtures\OpenApi\Missing::class)], which does not exist.');
        $this->generate([BrokenOpenApiModule::class]);
    }

    /**
     * @param list<class-string<\Trunk\Contracts\Module>> $modules
     *
     * @return array<string, mixed>
     */
    private function generate(array $modules): array
    {
        $configuration = new Configuration(['openapi' => ['title' => 'Shop', 'version' => '2.0.0', 'description' => '', 'servers' => ['https://api.example.com'], 'mode' => 'development', 'security' => [RequireApiKey::class => ['apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key']]]]]);

        return new OpenApi(new Runtime(Environment::Local, false, sys_get_temp_dir()), $configuration)->generate(new ModuleManifest([HttpModule::class, ValidationModule::class, OpenApiModule::class, ...$modules]));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function array(string|int ...$path): array
    {
        $value = $this->at(...$path);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return list<array-key>
     */
    private function keys(string|int ...$path): array
    {
        $value = $this->at(...$path);
        self::assertIsArray($value);

        return array_keys($value);
    }

    /**
     * @return array<array-key, mixed>|scalar|null
     */
    private function at(string|int ...$path): mixed
    {
        $value = $this->document;

        foreach ($path as $key) {
            self::assertIsArray($value, 'at ' . $key);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        self::assertTrue(\is_array($value) || \is_scalar($value) || $value === null);

        return $value;
    }
}

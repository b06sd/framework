<?php

declare(strict_types=1);

namespace Trunk\OpenApi\Generator;

use Deprecated;
use ReflectionMethod;
use stdClass;
use Trunk\OpenApi\Attribute\Returns;
use Trunk\OpenApi\Exception\OpenApiException;
use Trunk\Router\Compiler\RouteTable;
use Trunk\Router\Definition\ArgumentKind;
use Trunk\Router\Pattern\PatternParser;
use Trunk\Validation\Source;

/**
 * Builds an OpenAPI 3.1 document from the compiled routes: one operation per route and method, path
 * parameters from the pattern and the handler's types, `#[Query]` fields as query parameters, the
 * `#[Body]` class as the request body, `#[Returns]` as responses, security from the route's middleware,
 * and the summary from the first line of the handler's doc comment.
 *
 * @phpstan-import-type RouteEntry from RouteTable
 *
 * @internal
 */
final class DocumentGenerator
{
    /** Middleware that means "this operation needs credentials", and how a client sends them. */
    public const array SECURITY = [
        'Trunk\\Auth\\Http\\RequireToken' => ['apiToken' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'trk_<id>.<secret>', 'description' => 'An API token, from `trunk auth:token` or the tokens page.']],
        'Trunk\\Auth\\Http\\RequireJwt' => ['jwt' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => 'An access token from the identity provider.']],
        'Trunk\\Auth\\Http\\RequireLogin' => ['session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'session', 'description' => 'The session cookie set at sign-in (`__Host-session` over https).']],
    ];

    private const array REASONS = [200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 410 => 'Gone', 422 => 'Unprocessable Content', 429 => 'Too Many Requests'];

    /**
     * @param array{title: string, version: string, description: string, servers: list<string>} $info
     * @param array<string, array<string, array<string, mixed>>>                                     $security middleware class => [scheme name => scheme], added to SECURITY
     * @param list<class-string>                                                                     $hidden   handler classes left out (the docs' own routes)
     */
    public function __construct(
        private readonly array $info,
        private readonly array $security = [],
        private readonly array $hidden = [],
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws OpenApiException
     */
    public function generate(RouteTable $table): array
    {
        $schemas = new SchemaBuilder();
        $security = [...self::SECURITY, ...$this->security];
        $paths = [];
        $schemes = [];
        $operationIds = [];
        $errors = [];

        foreach ($table->routes as $route) {
            if (\in_array($route['handler'][0], $this->hidden, true)) {
                continue;
            }

            $methods = array_values(array_diff($route['methods'], \in_array('GET', $route['methods'], true) ? ['HEAD', 'OPTIONS'] : ['OPTIONS']));

            try {
                [$operation, $query, $responses] = $this->operation($route, $schemas);
            } catch (OpenApiException $e) {
                $errors[] = \sprintf('Route %s: %s', $route['pattern'], $e->getMessage());

                continue;
            }

            $requirements = [];

            foreach ($route['middleware'] as $middleware) {
                foreach ($security[$middleware] ?? [] as $name => $scheme) {
                    $schemes[$name] = $scheme;
                    $requirements[] = [$name => []];
                }
            }

            if ($requirements !== []) {
                $operation['security'] = $requirements;
                $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
                ksort($responses, \SORT_STRING);
            }

            foreach ($this->paths($route) as [$path, $parameters]) {
                foreach ($methods as $method) {
                    $id = $this->operationId($route, $method, \count($methods) > 1, $operationIds);
                    $parameters = [...$parameters, ...$query];
                    $paths[$path][strtolower($method)] = ['operationId' => $id, ...$operation, ...($parameters === [] ? [] : ['parameters' => $parameters]), 'responses' => $responses];
                }
            }
        }

        if ($errors !== []) {
            throw new OpenApiException(implode("\n", $errors));
        }

        foreach ($paths as $path => $operations) {
            ksort($operations);
            $paths[$path] = $operations;
        }

        ksort($paths);
        ksort($schemes);
        $paths = $schemas->resolve($paths);

        return array_filter([
            'openapi' => '3.1.0',
            'info' => array_filter(['title' => $this->info['title'], 'version' => $this->info['version'], 'description' => $this->info['description']], static fn(string $v): bool => $v !== ''),
            'servers' => array_map(static fn(string $url): array => ['url' => $url], $this->info['servers']),
            'paths' => $paths === [] ? new stdClass() : $paths,
            'components' => array_filter([
                'schemas' => [...$schemas->components(), ...self::errorSchemas()],
                'responses' => self::errorResponses(),
                'securitySchemes' => $schemes,
            ], static fn(array $v): bool => $v !== []),
        ], static fn(mixed $v): bool => $v !== []);
    }

    /**
     * @param RouteEntry $route
     *
     * @return array{array<string, mixed>, list<array<string, mixed>>, array<int|string, mixed>} the operation's fields, its query parameters and its responses
     */
    private function operation(array $route, SchemaBuilder $schemas): array
    {
        [$class, $method] = $route['handler'];
        $reflection = new ReflectionMethod($class, $method);
        [$summary, $description] = self::docComment($reflection->getDocComment() ?: '');
        $parameters = [];
        $body = null;
        $hasInput = false;

        foreach ($route['args'] as $argument) {
            if ($argument['kind'] === ArgumentKind::Query->value && class_exists($argument['type'])) {
                $hasInput = true;

                foreach ($schemas->plan($argument['type'])->fields as $field) {
                    $parameters[] = ['name' => $field->name, 'in' => 'query', 'required' => SchemaBuilder::isRequired($field), 'schema' => $schemas->field($field)];
                }
            } elseif ($argument['kind'] === ArgumentKind::Body->value && class_exists($argument['type'])) {
                $hasInput = true;
                $reference = $schemas->input($argument['type']);
                $types = $schemas->plan($argument['type'])->source === Source::Json ? ['application/json'] : ['application/json', 'application/x-www-form-urlencoded'];
                $body = ['required' => true, 'content' => array_fill_keys($types, ['schema' => $reference])];
            }
        }

        $responses = [];

        foreach ($reflection->getAttributes(Returns::class) as $attribute) {
            $returns = $attribute->newInstance();

            if ($returns->class !== null && !class_exists($returns->class)) {
                throw new OpenApiException(\sprintf('%s::%s() is #[Returns(%d, %s::class)], which does not exist.', $class, $method, $returns->status, $returns->class));
            }

            $response = ['description' => $returns->description !== '' ? $returns->description : (self::REASONS[$returns->status] ?? 'Response')];

            if ($returns->class !== null) {
                $schema = $schemas->output($returns->class);
                $response['content'] = ['application/json' => ['schema' => $returns->list ? ['type' => 'array', 'items' => $schema] : $schema]];
            }

            $responses[(string) $returns->status] = $response;
        }

        if ($responses === []) {
            $responses['default'] = ['description' => 'Not documented yet: add #[Returns] to ' . substr(strrchr('\\' . $class, '\\') ?: $class, 1) . '::' . $method . '().'];
        }

        if ($hasInput) {
            $responses['422'] ??= ['$ref' => '#/components/responses/ValidationFailed'];
        }

        if (array_filter($route['args'], static fn(array $a): bool => $a['kind'] === ArgumentKind::Param->value) !== []) {
            $responses['404'] ??= ['$ref' => '#/components/responses/NotFound'];
        }

        ksort($responses, \SORT_STRING);

        $operation = array_filter([
            'tags' => [self::tag($class)],
            'summary' => $summary,
            'description' => $description,
            'deprecated' => $reflection->getAttributes(Deprecated::class) !== [] ? true : null,
            'requestBody' => $body,
        ], static fn(mixed $v): bool => $v !== null && $v !== '');

        return [$operation, $parameters, $responses];
    }

    /**
     * The OpenAPI path or paths of a route: `{id:int}` becomes `{id}`, and a route ending in an optional
     * parameter is listed twice, with and without it, since OpenAPI path parameters are always required.
     *
     * @param RouteEntry $route
     *
     * @return list<array{string, list<array<string, mixed>>}>
     */
    private function paths(array $route): array
    {
        $types = [];

        foreach ($route['args'] as $argument) {
            if ($argument['kind'] === ArgumentKind::Param->value) {
                $types[$argument['name']] = $argument['type'];
            }
        }

        $variants = [['', []]];

        foreach (new PatternParser()->parse($route['pattern'])->segments as $segment) {
            if ($segment->param === null) {
                foreach ($variants as $i => [$path, $parameters]) {
                    $variants[$i] = [$path . '/' . $segment->literal, $parameters];
                }

                continue;
            }

            $parameter = ['name' => $segment->param, 'in' => 'path', 'required' => true, 'schema' => self::pathSchema($types[$segment->param] ?? 'string', (string) $segment->regex)];
            $extended = array_map(static fn(array $variant): array => [$variant[0] . '/{' . $segment->param . '}', [...$variant[1], $parameter]], $variants);
            $variants = $segment->optional ? [...$variants, ...$extended] : $extended;
        }

        return array_map(static fn(array $variant): array => [$variant[0] === '' ? '/' : $variant[0], $variant[1]], $variants);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pathSchema(string $type, string $regex): array
    {
        return match ($type) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            default => $regex === '[^/]+' ? ['type' => 'string'] : ['type' => 'string', 'pattern' => '^' . $regex . '$'],
        };
    }

    /**
     * @param RouteEntry           $route
     * @param array<string, true>  $taken
     */
    private function operationId(array $route, string $method, bool $perMethod, array &$taken): string
    {
        $base = $route['name'] ?? lcfirst(self::tag($route['handler'][0])) . '.' . $route['handler'][1];
        $id = $perMethod ? $base . '.' . strtolower($method) : $base;

        for ($candidate = $id, $n = 2; isset($taken[$candidate]); ++$n) {
            $candidate = $id . $n;
        }

        $taken[$candidate] = true;

        return $candidate;
    }

    /**
     * `OrderController` is tagged `Order`.
     */
    private static function tag(string $class): string
    {
        $short = substr(strrchr('\\' . $class, '\\') ?: $class, 1);

        return str_ends_with($short, 'Controller') && $short !== 'Controller' ? substr($short, 0, -10) : $short;
    }

    /**
     * The first paragraph of a doc comment is the summary, the rest (up to the first @tag) the description.
     *
     * @return array{string, string}
     */
    private static function docComment(string $comment): array
    {
        $lines = array_map(static fn(string $line): string => preg_replace('#^\s*(?:/\*\*|\*/|\*)\s?#', '', rtrim($line)) ?? '', explode("\n", $comment));
        $text = [];

        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '@')) {
                break;
            }

            $text[] = $line;
        }

        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', trim(implode("\n", $text))) ?: []), static fn(string $p): bool => $p !== ''));
        $summary = preg_replace('/\s+/', ' ', $paragraphs[0] ?? '') ?? '';

        return [rtrim($summary, '.'), implode("\n\n", \array_slice($paragraphs, 1))];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function errorSchemas(): array
    {
        $error = ['type' => 'object', 'required' => ['code', 'message', 'requestId'], 'properties' => [
            'code' => ['type' => 'string', 'description' => 'Stable machine-readable code, e.g. NOT_FOUND.'],
            'message' => ['type' => 'string'],
            'requestId' => ['type' => 'string', 'description' => 'Quote it when reporting a problem; the logs carry the same id.'],
            'details' => ['type' => 'object'],
        ]];

        return [
            'Error' => ['type' => 'object', 'required' => ['error'], 'properties' => ['error' => $error]],
            'ValidationError' => ['type' => 'object', 'required' => ['error'], 'properties' => ['error' => [...$error, 'properties' => [...$error['properties'], 'details' => [
                'type' => 'object',
                'properties' => [
                    'fields' => ['type' => 'object', 'description' => 'Field path => messages; the first is the first rule that failed.', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    'rules' => ['type' => 'object', 'description' => 'Field path => code of the rule that failed.', 'additionalProperties' => ['type' => 'string']],
                ],
            ]]]]],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function errorResponses(): array
    {
        $error = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];

        return [
            'NotFound' => ['description' => 'Not Found', 'content' => $error],
            'Unauthenticated' => ['description' => 'Missing or invalid credentials', 'content' => $error],
            'ValidationFailed' => ['description' => 'The input was invalid (code VALIDATION_FAILED)', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]]],
        ];
    }
}

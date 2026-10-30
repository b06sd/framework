<?php

declare(strict_types=1);

namespace Trunk\OpenApi;

use JsonException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Project\ProjectLoader;
use Trunk\Foundation\Runtime;
use Trunk\OpenApi\Exception\OpenApiException;
use Trunk\OpenApi\Generator\DocumentGenerator;
use Trunk\OpenApi\Http\OpenApiController;
use Trunk\Router\Compiler\RouteArtifactWriter;
use Trunk\Router\Exception\RouteCompilationException;

/**
 * The application's OpenAPI 3.1 document. In development it is generated from the routes on each
 * call; in production it is the build/openapi.json that `trunk build` wrote. Settings in config/openapi.php.
 *
 * @api
 */
final readonly class OpenApi
{
    /** @internal wired by the container */
    public function __construct(private Runtime $runtime, private Configuration $configuration) {}

    /**
     * @return array<string, mixed>
     *
     * @throws OpenApiException
     */
    public function document(): array
    {
        if ($this->configuration->string('openapi.mode') === 'compiled') {
            return self::decode($this->load());
        }

        return $this->generate(new ModuleManifest(new ProjectLoader()->load($this->runtime->basePath)->modules));
    }

    /**
     * The document as pretty-printed JSON.
     *
     * @throws OpenApiException
     */
    public function json(): string
    {
        return $this->configuration->string('openapi.mode') === 'compiled' ? $this->load() : self::encode($this->document());
    }

    /**
     * @internal used by `trunk build`, `trunk openapi` and development mode
     *
     * @return array<string, mixed>
     *
     * @throws OpenApiException
     */
    public function generate(ModuleManifest $manifest): array
    {
        try {
            $table = new RouteArtifactWriter()->compile($manifest);
        } catch (RouteCompilationException $e) {
            throw new OpenApiException(implode("\n", $e->errors), previous: $e);
        }

        $servers = $this->configuration->has('openapi.servers') ? array_values(array_filter($this->configuration->array('openapi.servers'), is_string(...))) : [];
        $security = [];

        foreach ($this->configuration->has('openapi.security') ? $this->configuration->array('openapi.security') : [] as $middleware => $schemes) {
            if (\is_string($middleware) && \is_array($schemes)) {
                /** @var array<string, array<string, mixed>> $schemes */
                $security[$middleware] = $schemes;
            }
        }

        return new DocumentGenerator(
            [
                'title' => $this->configuration->string('openapi.title'),
                'version' => $this->configuration->string('openapi.version'),
                'description' => $this->configuration->has('openapi.description') ? $this->configuration->string('openapi.description') : '',
                'servers' => $servers,
            ],
            $security,
            [OpenApiController::class],
        )->generate($table);
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $document
     */
    public static function encode(array $document): string
    {
        return json_encode($document, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function load(): string
    {
        $path = $this->configuration->string('openapi.build') . '/openapi.json';
        $json = is_file($path) ? file_get_contents($path) : false;

        return \is_string($json) ? $json : throw new OpenApiException(\sprintf('%s is missing: run `trunk build`.', $path));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        try {
            $document = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OpenApiException('build/openapi.json is not valid JSON: run `trunk build` again.', previous: $e);
        }

        if (!\is_array($document)) {
            throw new OpenApiException('build/openapi.json is not an OpenAPI document: run `trunk build` again.');
        }

        /** @var array<string, mixed> $document */
        return $document;
    }
}

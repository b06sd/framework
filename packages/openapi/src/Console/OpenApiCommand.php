<?php

declare(strict_types=1);

namespace Trunk\OpenApi\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Project\ProjectLoader;
use Trunk\Foundation\Runtime;
use Trunk\OpenApi\Exception\OpenApiException;
use Trunk\OpenApi\OpenApi;
use Trunk\Support\FileWriter;

/**
 * `trunk openapi [file]`: writes the OpenAPI document of the current routes (openapi.json by default),
 * for client generators, Postman or a docs site.
 */
final readonly class OpenApiCommand implements Command
{
    public function __construct(private OpenApi $openApi, private Runtime $runtime) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('openapi', 'Write the OpenAPI document of the routes to a file', ['file' => 'where to write it (default openapi.json)']);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $file = (string) ($input->argument(0) ?? 'openapi.json');
        $path = str_starts_with($file, '/') ? $file : $this->runtime->basePath . '/' . $file;

        if (!str_ends_with($path, '.json')) {
            $output->failure('Give a .json file, e.g. `trunk openapi docs/openapi.json`.');

            return 1;
        }

        try {
            $document = $this->openApi->generate(new ModuleManifest(new ProjectLoader()->load($this->runtime->basePath)->modules));
        } catch (OpenApiException $e) {
            $output->failure($e->getMessage());

            return 1;
        }

        $directory = \dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            $output->failure(\sprintf('Cannot create the directory %s.', $directory));

            return 1;
        }

        new FileWriter()->write($path, OpenApi::encode($document), 0o644);
        $paths = \is_array($document['paths'] ?? null) ? \count($document['paths']) : 0;
        $output->success(\sprintf('Wrote %s (%d %s).', $file, $paths, $paths === 1 ? 'path' : 'paths'));

        return 0;
    }
}

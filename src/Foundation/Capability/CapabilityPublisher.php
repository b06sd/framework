<?php

declare(strict_types=1);

namespace Trunk\Foundation\Capability;

use Trunk\Foundation\Exception\ProjectException;
use Trunk\Foundation\Project\EnvironmentFile;
use Trunk\Foundation\Project\Project;
use Trunk\Support\FileWriter;

/**
 * Adds what a capability needs to the project: its config file, its .env settings and its
 * directories. Nothing that already exists is ever overwritten.
 */
final readonly class CapabilityPublisher
{
    public function __construct(
        private FileWriter $files = new FileWriter(),
        private EnvironmentFile $environment = new EnvironmentFile(),
    ) {}

    /**
     * @return list<string> what was done, for display
     */
    public function publish(Project $project, Capability $capability): array
    {
        $changes = [];

        foreach ($capability->config as $name => $stub) {
            $destination = $project->configDirectory() . '/' . $name . '.php';

            if (is_file($destination)) {
                $changes[] = \sprintf('Kept your existing config/%s.php.', $name);

                continue;
            }

            $contents = file_get_contents($stub);

            if ($contents === false) {
                throw new ProjectException(\sprintf('The config stub for "%s" could not be read.', $name));
            }

            is_dir($project->configDirectory()) || mkdir($project->configDirectory(), 0o755, true);
            $this->files->write($destination, $contents);
            $changes[] = \sprintf('Created config/%s.php.', $name);
        }

        foreach (['.env.example', '.env'] as $file) {
            $added = $this->addEnvironment($project->path($file), $capability, $file === '.env.example');

            if ($added !== []) {
                $changes[] = \sprintf('Added %s to %s.', implode(', ', $added), $file);
            }
        }

        foreach ($capability->directories as $directory) {
            $path = $project->path($directory);

            if (!is_dir($path)) {
                mkdir($path, 0o755, true);
                $this->files->write($path . '/.gitkeep', '');
                $changes[] = \sprintf('Created %s/.', $directory);
            }
        }

        return $changes;
    }

    /**
     * @return list<string> the settings that were added
     */
    private function addEnvironment(string $file, Capability $capability, bool $createIfMissing): array
    {
        if ($capability->env === [] || (!is_file($file) && !$createIfMissing)) {
            return [];
        }

        $existing = is_file($file) ? (string) file_get_contents($file) : '';

        try {
            $present = $this->environment->parse($existing);
        } catch (ProjectException) {
            return [];
        }

        $missing = array_diff_key($capability->env, $present);

        if ($missing === []) {
            return [];
        }

        $block = ($existing === '' || str_ends_with($existing, "\n") ? '' : "\n") . ($existing === '' ? '' : "\n") . '# ' . $capability->name . "\n";

        foreach ($missing as $key => $value) {
            $block .= $key . '=' . (preg_match('/[\s#"\'\\\\]/', $value) === 1 ? '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"']) . '"' : $value) . "\n";
        }

        $this->files->write($file, $existing . $block);

        return array_keys($missing);
    }
}

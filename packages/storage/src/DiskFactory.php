<?php

declare(strict_types=1);

namespace Trunk\Storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Trunk\Storage\Exception\StorageException;

/**
 * Builds one Flysystem disk from its config entry. Every disk refuses `..` in a path (even one that
 * would stay inside the root) and writes private files unless told otherwise; the local driver keeps
 * files 0640 and directories 0750 and refuses symbolic links.
 *
 * @internal used by Storage
 */
final class DiskFactory
{
    /**
     * Problems with a disk's settings, each naming the fix. `$inUse`: an s3 disk must have its bucket
     * and region (at first use, and at build time for the default disk); a template entry nobody
     * uses may stay empty.
     *
     * @param array<array-key, mixed> $settings
     *
     * @return list<string>
     */
    public static function problems(string $name, array $settings, bool $inUse = true): array
    {
        $where = 'storage.disks.' . $name;
        $driver = $settings['driver'] ?? null;
        $s3Ready = \is_string($settings['bucket'] ?? null) && $settings['bucket'] !== '' && \is_string($settings['region'] ?? null) && $settings['region'] !== '';

        return match ($driver) {
            'local' => \is_string($settings['root'] ?? null) && str_starts_with($settings['root'], '/') ? [] : [$where . '.root must be an absolute directory path.'],
            's3' => $s3Ready || !$inUse ? [] : [$where . ' needs a bucket and a region (S3_BUCKET, S3_REGION in .env).'],
            'memory' => [],
            default => [$where . '.driver must be local, s3 or memory.'],
        };
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    public function make(string $name, array $settings): FilesystemOperator
    {
        $problems = self::problems($name, $settings);

        if ($problems !== []) {
            throw new StorageException($problems[0]);
        }

        $config = ['visibility' => 'private', 'directory_visibility' => 'private', 'allow_relative_path_traversal' => false];
        $publicUrl = $settings['public_url'] ?? null;

        if (\is_string($publicUrl) && $publicUrl !== '') {
            $config['public_url'] = $publicUrl;
        }

        return new Filesystem($this->adapter($name, $settings), $config);
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private function adapter(string $name, array $settings): FilesystemAdapter
    {
        return match ($settings['driver'] ?? null) {
            'local' => new LocalFilesystemAdapter(
                \is_string($settings['root'] ?? null) ? $settings['root'] : throw new StorageException(\sprintf('storage.disks.%s.root must be an absolute directory path.', $name)),
                PortableVisibilityConverter::fromArray(['file' => ['public' => 0o644, 'private' => 0o640], 'dir' => ['public' => 0o755, 'private' => 0o750]]),
                \LOCK_EX,
                LocalFilesystemAdapter::DISALLOW_LINKS,
            ),
            's3' => $this->s3($name, $settings),
            default => class_exists(InMemoryFilesystemAdapter::class)
                ? new InMemoryFilesystemAdapter()
                : throw new StorageException(\sprintf('storage.disks.%s uses the memory driver: run `composer require --dev league/flysystem-memory`.', $name)),
        };
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private function s3(string $name, array $settings): AwsS3V3Adapter
    {
        if (!class_exists(AwsS3V3Adapter::class)) {
            throw new StorageException(\sprintf('storage.disks.%s uses the s3 driver: run `composer require league/flysystem-aws-s3-v3`.', $name));
        }

        $string = static fn(string $key): string => \is_string($settings[$key] ?? null) ? $settings[$key] : '';
        $client = [
            'version' => 'latest',
            'region' => $string('region'),
            'use_path_style_endpoint' => ($settings['path_style'] ?? false) === true,
        ];

        if ($string('key') !== '') {
            $client['credentials'] = ['key' => $string('key'), 'secret' => $string('secret')];
        }

        if ($string('endpoint') !== '') {
            $client['endpoint'] = $string('endpoint');
        }

        return new AwsS3V3Adapter(new S3Client($client), $string('bucket'), $string('prefix'));
    }
}

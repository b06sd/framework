<?php

declare(strict_types=1);

namespace Trunk\Console;

use Composer\InstalledVersions;
use Throwable;

/**
 * The version of Trunk that is installed, as Composer knows it: `0.1.1` for a tag, `dev-main` for a
 * branch. Nothing to keep in step at release time.
 */
final class Version
{
    public static function current(): string
    {
        try {
            $installed = class_exists(InstalledVersions::class) ? InstalledVersions::getPrettyVersion('trunkphp/framework') : null;
        } catch (Throwable) {
            $installed = null;
        }

        return self::label($installed);
    }

    /**
     * @param string|null $installed Composer's pretty version, or null when Composer does not know the package
     */
    public static function label(?string $installed): string
    {
        $installed = trim((string) $installed);

        return $installed === '' ? 'dev' : (preg_match('/^v\d/', $installed) === 1 ? substr($installed, 1) : $installed);
    }
}

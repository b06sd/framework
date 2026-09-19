<?php

declare(strict_types=1);

namespace Trunk\Database\Migration;

use DateTimeImmutable;
use Trunk\Database\Exception\MigrationException;

/**
 * Creates new migration files. Never overwrites, and only writes inside the migrations directory.
 */
final readonly class MigrationStub
{
    public function create(string $directory, string $name, DateTimeImmutable $now): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,80}$/D', $name) !== 1) {
            throw new MigrationException('Migration names use lowercase snake_case, e.g. create_customers_table.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new MigrationException('The migrations directory could not be created.');
        }

        $path = $directory . '/' . $now->format('Y_m_d_His') . '_' . $name . '.php';

        if (file_exists($path)) {
            throw new MigrationException('That migration already exists; wait a second or pick another name.');
        }

        $table = preg_match('/^create_(.+)_table$/', $name, $m) === 1 ? $m[1] : null;
        $up = $table === null ? "        // \$schema->table('table', fn(Blueprint \$table) => ...);" : "        \$schema->create('{$table}', function (Blueprint \$table): void {\n            \$table->id();\n            \$table->timestamps();\n        });";
        $down = $table === null ? "        // Reverse what up() did." : "        \$schema->dropIfExists('{$table}');";
        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            use Trunk\Database\Migration\Migration;
            use Trunk\Database\Schema\Blueprint;
            use Trunk\Database\Schema\Schema;

            return new Migration(
                up: function (Schema \$schema): void {
            {$up}
                },
                down: function (Schema \$schema): void {
            {$down}
                },
            );

            PHP;

        if (file_put_contents($path, $contents, \LOCK_EX) === false) {
            throw new MigrationException('The migration file could not be written.');
        }

        return $path;
    }
}

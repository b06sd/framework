<?php

declare(strict_types=1);

namespace Trunk\RateLimit\Console;

/**
 * The migration `trunk rate-limit:table` writes. The table name comes from validated config, so it
 * is safe to write into the file.
 */
final class RateLimitTables
{
    public static function migration(string $table): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Trunk\\Database\\Migration\\Migration;
            use Trunk\\Database\\Schema\\Blueprint;
            use Trunk\\Database\\Schema\\Schema;

            return new Migration(
                up: function (Schema \$schema): void {
                    \$schema->create('{$table}', function (Blueprint \$table): void {
                        \$table->string('key_hash', 64)->primary();
                        \$table->integer('attempts');
                        \$table->bigInteger('window_start');
                    });
                },
                down: function (Schema \$schema): void {
                    \$schema->dropIfExists('{$table}');
                },
            );

            PHP;
    }
}

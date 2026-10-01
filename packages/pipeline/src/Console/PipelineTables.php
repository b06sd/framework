<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Console;

/**
 * The migration `trunk pipeline:table` writes.
 */
final class PipelineTables
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
                    \$schema->create('{$table}', function (Blueprint \$t): void {
                        \$t->id();
                        \$t->string('pipeline', 255);
                        \$t->string('status', 16);
                        \$t->bigInteger('cursor')->default(0);
                        \$t->bigInteger('records_processed')->default(0);
                        \$t->integer('chunk_size');
                        \$t->bigInteger('started_at');
                        \$t->bigInteger('updated_at');
                        \$t->bigInteger('completed_at')->nullable();
                        \$t->index(['pipeline', 'status']);
                    });
                },
                down: function (Schema \$schema): void {
                    \$schema->dropIfExists('{$table}');
                },
            );

            PHP;
    }
}

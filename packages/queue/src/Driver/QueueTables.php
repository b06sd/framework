<?php

declare(strict_types=1);

namespace Trunk\Queue\Driver;

/**
 * The migration `trunk queue:table` writes for the database driver.
 */
final class QueueTables
{
    public static function migration(string $table, string $failedTable): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Trunk\Database\Migration\Migration;
            use Trunk\Database\Schema\Blueprint;
            use Trunk\Database\Schema\Schema;

            return new Migration(
                up: function (Schema \$schema): void {
                    \$schema->create('{$table}', function (Blueprint \$table): void {
                        \$table->id();
                        \$table->string('queue', 64);
                        \$table->string('job', 255);
                        \$table->text('payload');
                        \$table->integer('attempts')->default(0);
                        \$table->bigInteger('available_at');
                        \$table->bigInteger('reserved_at')->nullable();
                        \$table->string('reserved_by', 64)->nullable();
                        \$table->text('origin')->nullable();
                        \$table->bigInteger('created_at');
                        \$table->index(['queue', 'available_at', 'reserved_at']);
                    });
                    \$schema->create('{$failedTable}', function (Blueprint \$table): void {
                        \$table->id();
                        \$table->string('queue', 64);
                        \$table->string('job', 255);
                        \$table->text('payload');
                        \$table->string('exception', 255);
                        \$table->text('message')->nullable();
                        \$table->bigInteger('failed_at');
                    });
                },
                down: function (Schema \$schema): void {
                    \$schema->dropIfExists('{$failedTable}');
                    \$schema->dropIfExists('{$table}');
                },
            );

            PHP;
    }
}

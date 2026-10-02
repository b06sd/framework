<?php

declare(strict_types=1);

namespace Trunk\Auth\Console;

use Trunk\Auth\Settings\AuthSettings;

/**
 * The migrations `trunk auth:table` writes. Table and column names come from validated config, so they
 * are safe to write into the file.
 */
final class AuthTables
{
    public static function migration(AuthSettings $settings, bool $withUsers = false): string
    {
        $sessions = $settings->session->table;
        $tokens = $settings->tokens->table;
        $throttles = $settings->throttle->table;
        $links = $settings->links->table;
        $linksUp = self::linksTable($links);
        $users = $settings->users;
        $usersUp = '';
        $usersDown = '';

        if ($withUsers) {
            $usersUp = <<<PHP

                        \$schema->create('{$users->table}', function (Blueprint \$table): void {
                            \$table->id('{$users->id}');
                            \$table->string('name', 150);
                            \$table->string('{$users->identifier}', 190)->unique();
                            \$table->string('{$users->password}', 255);
                            \$table->string('{$users->sessionVersion}', 64)->default('1');
                            \$table->bigInteger('{$users->verifiedAt}')->nullable();
                            \$table->timestamps();
                        });
                PHP;
            $usersDown = "\n        \$schema->dropIfExists('{$users->table}');";
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Trunk\\Database\\Migration\\Migration;
            use Trunk\\Database\\Schema\\Blueprint;
            use Trunk\\Database\\Schema\\Schema;

            return new Migration(
                up: function (Schema \$schema): void {
                    \$schema->create('{$sessions}', function (Blueprint \$table): void {
                        \$table->string('id_hash', 64)->primary();
                        \$table->text('payload');
                        \$table->bigInteger('created_at');
                        \$table->bigInteger('last_activity');
                        \$table->index('last_activity');
                    });
                    \$schema->create('{$tokens}', function (Blueprint \$table): void {
                        \$table->string('id', 32)->primary();
                        \$table->string('token_hash', 64);
                        \$table->string('user_id', 64);
                        \$table->string('user_version', 64);
                        \$table->string('name', 100);
                        \$table->text('abilities');
                        \$table->bigInteger('expires_at')->nullable();
                        \$table->bigInteger('revoked_at')->nullable();
                        \$table->bigInteger('last_used_at')->nullable();
                        \$table->bigInteger('created_at');
                        \$table->index('user_id');
                    });
                    \$schema->create('{$throttles}', function (Blueprint \$table): void {
                        \$table->string('key_hash', 64)->primary();
                        \$table->integer('attempts');
                        \$table->bigInteger('window_start');
                    });
            {$linksUp}{$usersUp}
                },
                down: function (Schema \$schema): void {{$usersDown}
                    \$schema->dropIfExists('{$links}');
                    \$schema->dropIfExists('{$throttles}');
                    \$schema->dropIfExists('{$tokens}');
                    \$schema->dropIfExists('{$sessions}');
                },
            );

            PHP;
    }

    /**
     * For a project whose auth tables predate one-time links: the links table, and the users table's
     * verified column when that table exists without it.
     */
    public static function linksMigration(AuthSettings $settings): string
    {
        $links = $settings->links->table;
        $linksUp = self::linksTable($links);
        $users = $settings->users;

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Trunk\\Database\\Migration\\Migration;
            use Trunk\\Database\\Schema\\Blueprint;
            use Trunk\\Database\\Schema\\Schema;

            return new Migration(
                up: function (Schema \$schema): void {
            {$linksUp}
                    if (\$schema->hasTable('{$users->table}') && !\$schema->hasColumn('{$users->table}', '{$users->verifiedAt}')) {
                        \$schema->table('{$users->table}', function (Blueprint \$table): void {
                            \$table->bigInteger('{$users->verifiedAt}')->nullable();
                        });
                    }
                },
                down: function (Schema \$schema): void {
                    \$schema->dropIfExists('{$links}');
                },
            );

            PHP;
    }

    private static function linksTable(string $links): string
    {
        return <<<PHP
                    \$schema->create('{$links}', function (Blueprint \$table): void {
                        \$table->string('id', 32)->primary();
                        \$table->string('token_hash', 64);
                        \$table->string('purpose', 16);
                        \$table->string('user_id', 64);
                        \$table->string('user_version', 64);
                        \$table->string('address', 190)->nullable();
                        \$table->bigInteger('expires_at');
                        \$table->bigInteger('created_at');
                        \$table->index('user_id');
                    });
            PHP;
    }
}

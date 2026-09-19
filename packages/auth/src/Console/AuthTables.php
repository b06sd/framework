<?php

declare(strict_types=1);

namespace Trunk\Auth\Console;

use Trunk\Auth\Settings\AuthSettings;

/**
 * The migration `trunk auth:table` writes. Table names come from validated config, so they are safe
 * to write into the file.
 */
final class AuthTables
{
    public static function migration(AuthSettings $settings, bool $withUsers = false): string
    {
        $sessions = $settings->session->table;
        $tokens = $settings->tokens->table;
        $throttles = $settings->throttle->table;
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
                    });{$usersUp}
                },
                down: function (Schema \$schema): void {{$usersDown}
                    \$schema->dropIfExists('{$throttles}');
                    \$schema->dropIfExists('{$tokens}');
                    \$schema->dropIfExists('{$sessions}');
                },
            );

            PHP;
    }
}

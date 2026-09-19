<?php

declare(strict_types=1);

namespace Trunk\Database\Migration;

use Closure;
use Trunk\Database\Schema\Schema;

/**
 * What a migration file returns:
 *
 *   return new Migration(
 *       up: function (Schema $schema): void { $schema->create('users', ...); },
 *       down: function (Schema $schema): void { $schema->drop('users'); },
 *   );
 *
 * @api
 */
final readonly class Migration
{
    /**
     * @param Closure(Schema): void $up
     * @param Closure(Schema): void $down
     */
    public function __construct(public Closure $up, public Closure $down) {}
}

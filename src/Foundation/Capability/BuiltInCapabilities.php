<?php

declare(strict_types=1);

namespace Trunk\Foundation\Capability;

/**
 * The capabilities shipped inside trunk/framework. Module names are strings on purpose: the core
 * must not depend on the packages it describes.
 */
final class BuiltInCapabilities
{
    /**
     * @return list<Capability>
     */
    public function all(): array
    {
        $root = \dirname(__DIR__, 3);

        return [
            new Capability('http', 'HTTP', 'HTTP kernel, router and middleware', ['Trunk\\Http\\HttpModule'], config: ['http' => $root . '/packages/http/resources/config/http.php'], composer: ['psr/http-factory' => '^1.0', 'psr/http-message' => '^2.0', 'psr/http-server-handler' => '^1.0', 'psr/http-server-middleware' => '^1.0']),
            new Capability(
                'logging',
                'Logging',
                'Structured PSR-3 logging with request ids and secret redaction',
                ['Trunk\\Foundation\\Logging\\LoggingModule'],
                config: ['logging' => $root . '/resources/config/logging.php'],
                directories: ['storage/logs'],
            ),
            new Capability(
                'diagnostics',
                'Diagnostics',
                'Error pipeline, request/job lifecycle reset and health checks',
                ['Trunk\\Foundation\\Diagnostics\\DiagnosticsModule'],
                requires: ['logging'],
                config: ['errors' => $root . '/resources/config/errors.php'],
            ),
            new Capability(
                'tusk',
                'Tusk',
                'Tusk templates (*.tusk.php)',
                ['Trunk\\Tusk\\TuskModule'],
                config: ['views' => $root . '/packages/tusk/resources/config/views.php'],
                directories: ['resources/views'],
            ),
            new Capability('mvc', 'MVC', 'MVC responder: views, JSON and safe redirects', ['Trunk\\Mvc\\MvcModule'], requires: ['http', 'tusk']),
            new Capability('console', 'Console', 'Application console commands', ['Trunk\\Console\\ConsoleModule']),
            new Capability(
                'cache',
                'Cache',
                'PSR-16 cache (array, file, null stores)',
                ['Trunk\\Cache\\CacheModule'],
                config: ['cache' => $root . '/packages/cache/resources/config/cache.php'],
                env: ['CACHE_DRIVER' => 'file'],
                composer: ['psr/simple-cache' => '^3.0'],
                integrations: ['console' => ['Trunk\\Cache\\Console\\CacheConsoleModule']],
            ),
            new Capability(
                'database',
                'Database',
                'TrunkDB database layer: connections, query builder, schema and migrations',
                ['Trunk\\Database\\DatabaseModule'],
                config: ['database' => $root . '/packages/database/resources/config/database.php'],
                env: ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'storage/database.sqlite'],
                directories: ['database/migrations'],
                composer: ['ext-pdo' => '*'],
                integrations: ['console' => ['Trunk\\Database\\Console\\DatabaseConsoleModule']],
            ),
            new Capability(
                'orm',
                'ORM',
                'TrunkORM: entities, explicit mapping classes, repositories and a unit of work',
                ['Trunk\\Orm\\OrmModule'],
                requires: ['database'],
                config: ['orm' => $root . '/packages/orm/resources/config/orm.php'],
                directories: ['app/Orm'],
                integrations: ['console' => ['Trunk\\Orm\\Console\\OrmConsoleModule']],
            ),
            new Capability(
                'queue',
                'Queue',
                'TrunkQueue: typed jobs, a database queue and a worker',
                ['Trunk\\Queue\\QueueModule'],
                requires: ['database', 'diagnostics'],
                config: ['queue' => $root . '/packages/queue/resources/config/queue.php'],
                env: ['QUEUE_CONNECTION' => 'sqlite'],
                directories: ['app/Jobs'],
                integrations: ['console' => ['Trunk\\Queue\\Console\\QueueConsoleModule']],
            ),
            new Capability(
                'observability',
                'Observability',
                'Metrics (Prometheus text format) and log-based tracing',
                ['Trunk\\Telemetry\\TelemetryModule'],
                requires: ['logging'],
                config: ['observability' => $root . '/packages/observability/resources/config/observability.php'],
            ),
            new Capability(
                'auth',
                'Auth',
                'Password hashing, sessions, CSRF protection, bearer tokens, login throttling and authorization policies',
                ['Trunk\\Auth\\AuthModule'],
                requires: ['http', 'database', 'diagnostics'],
                config: ['auth' => $root . '/packages/auth/resources/config/auth.php'],
                integrations: ['console' => ['Trunk\\Auth\\Console\\AuthConsoleModule']],
            ),
            new Capability(
                'health',
                'Health',
                'Liveness and readiness endpoints, and an optional token-protected /metrics',
                ['Trunk\\Http\\Health\\HealthModule'],
                requires: ['http', 'diagnostics'],
                config: ['health' => $root . '/resources/config/health.php'],
            ),
        ];
    }
}

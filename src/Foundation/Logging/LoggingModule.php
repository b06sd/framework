<?php

declare(strict_types=1);

namespace Trunk\Foundation\Logging;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Runtime;
use Trunk\Logging\ContextHolder;
use Trunk\Logging\LoggerFactory;
use Trunk\Logging\Logs;

/**
 * Binds LoggerInterface (PSR-3) to a structured logger configured by config/logging.php: JSON lines
 * with request/trace ids, redaction of secrets and control-character sanitising. Inject
 * LoggerInterface anywhere; there is nothing Trunk-specific to learn. Inject Logs instead for a logger
 * per category with its own level (`levels` in config/logging.php). `trunk build` validates the
 * logging configuration.
 * The error pipeline, lifecycle and health services live in DiagnosticsModule, which builds on this.
 *
 * @api
 */
final class LoggingModule implements Module, BuildContributor
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->service(ContextHolder::class, ContextHolder::class);
        $builder->service(LoggerFactory::class, LoggerFactory::class);
        $builder->tag('trunk.lifecycle', ContextHolder::class);
        $builder->factory(LoggerInterface::class, [LoggerFactory::class, 'create'], [
            new Reference(Configuration::class),
            new Reference(Runtime::class),
            new Reference(ContextHolder::class),
        ]);
        $builder->factory(Logs::class, [LoggerFactory::class, 'logs'], [new Reference(LoggerInterface::class), new Reference(Configuration::class)]);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        $problems = LoggerFactory::problems($context->configuration);

        if ($problems !== []) {
            throw new CompilationException($problems);
        }

        return new BuildContribution();
    }
}

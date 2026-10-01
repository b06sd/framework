<?php

declare(strict_types=1);

namespace Trunk\Mail;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Foundation\Runtime;
use Trunk\Mail\Exception\MailException;

/**
 * Email over Symfony Mailer: inject `Mailer` and send a Symfony `Email`. Configured by config/mail.php
 * (`MAIL_DSN`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`); `trunk build` checks the sender.
 *
 * @api
 */
final class MailModule implements Module, BuildContributor, ModuleDependencies
{
    public function requires(): array
    {
        return [LoggingModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->service(MailerFactory::class, MailerFactory::class);
        $builder->factory(Mailer::class, [MailerFactory::class, 'create'], [
            new Reference(Configuration::class),
            new Reference(Runtime::class),
            new Reference(LoggerInterface::class),
        ]);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        try {
            MailerFactory::from($context->configuration);
        } catch (MailException $e) {
            throw new CompilationException(['config/mail.php: ' . $e->getMessage()]);
        }

        return new BuildContribution();
    }
}

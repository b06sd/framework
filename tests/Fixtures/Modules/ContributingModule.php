<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Tests\Fixtures\Di\Token;

final class ContributingModule implements Module, BuildContributor
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        return new BuildContribution([Token::class], [
            static function (string $directory) use ($context): void {
                file_put_contents($directory . '/contributed.txt', 'from ' . $context->runtime->environment->value);
            },
        ]);
    }
}

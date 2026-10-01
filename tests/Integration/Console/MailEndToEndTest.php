<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * The mail capability in a project made by the real `trunk new`: installed, configured from .env,
 * checked by `trunk build`, and tried with `trunk mail:test`.
 */
final class MailEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_installing_mail_sets_it_up_and_mail_test_writes_the_email_in_development(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');

        // Act
        [$installCode, $installOut] = $project->trunk(['package:install', 'mail']);
        $project->trunk(['package:install', 'console']);
        [$testCode, $testOut, $testErr] = $project->trunk(['mail:test', 'ada@example.com'], environment: ['APP_ENV' => 'local']);
        $files = glob($project->directory . '/storage/mail/*.eml') ?: [];

        // Assert
        self::assertSame(0, $installCode, $installOut);
        self::assertFileExists($project->directory . '/config/mail.php');
        self::assertStringContainsString('MAIL_DSN=file://default', (string) file_get_contents($project->directory . '/.env'));
        self::assertSame(0, $testCode, $testOut . $testErr);
        self::assertStringContainsString('Sent a test email to ada@example.com.', $testOut);
        self::assertCount(1, $files);
        self::assertStringContainsString('To: ada@example.com', (string) file_get_contents($files[0]));
        self::assertStringContainsString('Subject: Trunk mail test', (string) file_get_contents($files[0]));
    }

    public function test_production_refuses_the_development_transport_and_the_build_refuses_a_bad_sender(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'mail']);
        $project->trunk(['package:install', 'console']);

        [$goodBuild, $goodBuildOut] = $project->trunk(['build']);

        // Act: MAIL_DSN is a secret, read when the process starts, so it is not part of the build
        [$productionCode, $productionOut, $productionErr] = $project->trunk(['mail:test', 'ada@example.com'], environment: ['APP_ENV' => 'production', 'MAIL_DSN' => 'file://default']);
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build'], environment: ['MAIL_FROM_ADDRESS' => 'not-an-address']);

        // Assert
        self::assertSame(0, $goodBuild, $goodBuildOut);
        self::assertNotSame(0, $productionCode);
        self::assertStringContainsString('file:// (development only) in production', $productionOut . $productionErr);
        self::assertSame([], glob($project->directory . '/storage/mail/*.eml') ?: []);
        self::assertNotSame(0, $buildCode);
        self::assertStringContainsString('config/mail.php: mail.from.address must be an email address', $buildOut . $buildErr);
    }
}

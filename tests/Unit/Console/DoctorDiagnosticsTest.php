<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Console\Commands\DoctorCommand;
use Trunk\Console\Input\Input;
use Trunk\Foundation\Project\Project;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Modules\AlphaModule;
use Trunk\Tests\Support\OutputCapture;

/**
 * The doctor's diagnostics section: log directory, APP_DEBUG in production, ext-pcntl for the queue.
 */
final class DoctorDiagnosticsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-doctor-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/storage', 0o755, true);
        file_put_contents($this->directory . '/trunk.php', "<?php\nreturn [];\n");
    }

    protected function tearDown(): void
    {
        @chmod($this->directory . '/storage', 0o755);
        new Directory()->remove($this->directory);
    }

    public function test_app_debug_is_flagged_only_in_production_and_only_when_set(): void
    {
        // Arrange & Act
        [, $productionDebug] = $this->doctor([], ['APP_ENV' => 'production', 'APP_DEBUG' => '1']);
        [, $productionOff] = $this->doctor([], ['APP_ENV' => 'production', 'APP_DEBUG' => '0']);
        [, $productionUnset] = $this->doctor([], ['APP_ENV' => 'production']);
        [, $local] = $this->doctor([], ['APP_ENV' => 'local', 'APP_DEBUG' => '1']);

        // Assert
        self::assertStringContainsString('APP_DEBUG is set, but it is ignored in production', $productionDebug);
        foreach ([$productionOff, $productionUnset, $local] as $output) {
            self::assertStringNotContainsString('APP_DEBUG is set', $output);
        }
    }

    public function test_third_party_capabilities_are_listed_with_their_package_and_a_trust_warning(): void
    {
        // Arrange
        $vendor = $this->directory . '/vendor/acme/payments';
        mkdir($vendor, 0o755, true);
        mkdir($this->directory . '/vendor/composer', 0o755, true);
        file_put_contents($this->directory . '/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'acme/payments', 'install-path' => '../acme/payments', 'extra' => ['trunk' => ['capability' => ['id' => 'payments', 'name' => 'Payments', 'modules' => [AlphaModule::class]]]]]]], \JSON_THROW_ON_ERROR));

        // Act
        [, $with] = $this->doctor([AlphaModule::class], ['APP_ENV' => 'local']);
        [, $without] = $this->doctor([], ['APP_ENV' => 'local']);

        // Assert
        self::assertStringContainsString('[acme/payments]', $with);
        self::assertStringContainsString('third-party package acme/payments', $with);
        self::assertStringNotContainsString('third-party', $without);
    }

    public function test_doctor_and_the_missing_package_fix_are_reported_when_an_enabled_capability_lacks_its_composer_packages(): void
    {
        // Arrange
        mkdir($this->directory . '/vendor/composer', 0o755, true);
        file_put_contents($this->directory . '/vendor/autoload.php', "<?php\n");
        file_put_contents($this->directory . '/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'psr/http-message']]], \JSON_THROW_ON_ERROR));

        // Act
        [$code, $without] = $this->doctor([\Trunk\Http\HttpModule::class], ['APP_ENV' => 'local']);
        [, $none] = $this->doctor([], ['APP_ENV' => 'local']);

        // Assert
        self::assertSame(1, $code);
        self::assertStringContainsString('Capability dependencies: missing Composer packages; run `composer require psr/http-factory:^1.1 psr/http-server-handler:^1.0 psr/http-server-middleware:^1.0`', $without);
        self::assertStringContainsString('✓ Capability dependencies', $none);
    }

    public function test_the_pcntl_warning_appears_only_when_the_queue_is_enabled_and_pcntl_is_missing(): void
    {
        // Arrange
        $queue = [\Trunk\Queue\QueueModule::class];

        // Act
        [, $missing] = $this->doctor($queue, ['APP_ENV' => 'local'], false);
        [, $present] = $this->doctor($queue, ['APP_ENV' => 'local'], true);
        [, $noQueue] = $this->doctor([], ['APP_ENV' => 'local'], false);

        // Assert
        self::assertStringContainsString('ext-pcntl is not installed', $missing);
        self::assertStringNotContainsString('ext-pcntl', $present);
        self::assertStringNotContainsString('ext-pcntl', $noQueue);
    }

    public function test_an_unwritable_log_directory_is_a_problem_only_for_the_file_channel(): void
    {
        // Arrange
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permissions are not enforced for root.');
        }

        chmod($this->directory . '/storage', 0o555);
        $logging = [\Trunk\Foundation\Logging\LoggingModule::class];

        // Act
        [$fileCode, $fileOutput] = $this->doctor($logging, ['APP_ENV' => 'local', 'LOG_CHANNEL' => 'file']);
        [, $stderrOutput] = $this->doctor($logging, ['APP_ENV' => 'local', 'LOG_CHANNEL' => 'stderr']);
        chmod($this->directory . '/storage', 0o755);
        [, $writableOutput] = $this->doctor($logging, ['APP_ENV' => 'local', 'LOG_CHANNEL' => 'file']);

        // Assert
        self::assertNotSame(0, $fileCode);
        self::assertStringContainsString('Log directory (storage/logs) is writable: Create it and make it writable', $fileOutput);
        self::assertStringContainsString('Logging to stderr', $stderrOutput);
        self::assertStringContainsString('Log directory (storage/logs) is writable', $writableOutput);
        self::assertStringNotContainsString('Create it and make it writable', $writableOutput);
    }

    public function test_world_readable_secrets_files_and_world_writable_logs_are_warned_about(): void
    {
        // Arrange
        file_put_contents($this->directory . '/.env', 'X=1');
        chmod($this->directory . '/.env', 0o644);
        mkdir($this->directory . '/build');
        file_put_contents($this->directory . '/build/config.php', '<?php return [];');
        chmod($this->directory . '/build/config.php', 0o644);
        mkdir($this->directory . '/storage/logs', 0o777);
        chmod($this->directory . '/storage/logs', 0o777);

        // Act
        [, $loose] = $this->doctor([], ['APP_ENV' => 'local']);
        chmod($this->directory . '/.env', 0o640);
        chmod($this->directory . '/build/config.php', 0o640);
        chmod($this->directory . '/storage/logs', 0o750);
        [, $tight] = $this->doctor([], ['APP_ENV' => 'local']);

        // Assert
        self::assertStringContainsString('.env is readable by every user', $loose);
        self::assertStringContainsString('build/config.php is readable by every user', $loose);
        self::assertStringContainsString('storage/logs is writable by every user', $loose);
        self::assertStringNotContainsString('readable by every user', $tight);
        self::assertStringNotContainsString('writable by every user', $tight);
    }

    /**
     * @param list<class-string<\Trunk\Contracts\Module>> $modules
     * @param array<string, string>                       $variables
     *
     * @return array{int, string}
     */
    private function doctor(array $modules, array $variables, bool $pcntl = true): array
    {
        $capture = new OutputCapture();
        $code = new DoctorCommand(new Project($this->directory, 'demo', 'api', $modules), $variables, true, pcntlAvailable: static fn(): bool => $pcntl)->handle(Input::fromArgv(['trunk', 'doctor']), $capture->output);

        return [$code, $capture->stdout() . $capture->stderr()];
    }
}

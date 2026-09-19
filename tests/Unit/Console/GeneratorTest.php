<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Console;

use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Console\Scaffold\Generator;
use Trunk\Console\Scaffold\Profile;
use Trunk\Console\Scaffold\ProjectScaffolder;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Project\Project;
use Trunk\Support\Directory;

final class GeneratorTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/trunk-generate-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->base);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function kinds(): iterable
    {
        yield 'controller' => ['controller', 'InvoiceController', 'app/Controllers/InvoiceController.php'];
        yield 'service' => ['service', 'InvoiceService', 'app/Services/InvoiceService.php'];
        yield 'middleware' => ['middleware', 'AuthMiddleware', 'app/Middleware/AuthMiddleware.php'];
        yield 'command' => ['command', 'SyncInvoicesCommand', 'app/Commands/SyncInvoicesCommand.php'];
        yield 'module' => ['module', 'Billing', 'app/Billing/BillingModule.php'];
        yield 'test' => ['test', 'InvoiceService', 'tests/InvoiceServiceTest.php'];
    }

    #[DataProvider('kinds')]
    public function test_each_generator_writes_valid_php_in_the_conventional_place(string $kind, string $name, string $expected): void
    {
        // Arrange
        $project = $this->project();

        // Act
        $file = new Generator()->make($project, $kind, $name);
        $contents = (string) file_get_contents($project->path($file->path));

        // Assert
        self::assertSame($expected, $file->path);
        self::assertStringContainsString('declare(strict_types=1);', $contents);
        PhpToken::tokenize($contents, \TOKEN_PARSE);
        self::assertStringNotContainsString('%%', $contents);
        self::assertNotSame('', $file->hint);
    }

    public function test_the_namespace_comes_from_the_projects_composer_mapping(): void
    {
        // Arrange
        $project = $this->project();
        file_put_contents($project->path('composer.json'), str_replace('"App\\\\": "app/"', '"Acme\\\\Crm\\\\": "app/"', (string) file_get_contents($project->path('composer.json'))));

        // Act
        $file = new Generator()->make($project, 'service', 'Mailer');

        // Assert
        self::assertStringContainsString('namespace Acme\Crm\Services;', (string) file_get_contents($project->path($file->path)));
    }

    public function test_controllers_use_the_responder_only_when_the_project_has_views(): void
    {
        // Arrange
        $api = $this->project();
        $web = new Project($api->basePath, 'demo', 'web', ['Trunk\\Mvc\\MvcModule']);

        // Act
        $apiFile = new Generator()->make($api, 'controller', 'JsonController');
        $webFile = new Generator()->make($web, 'controller', 'PageController');

        // Assert
        self::assertStringContainsString('ResponseBuilder', (string) file_get_contents($api->path($apiFile->path)));
        self::assertStringContainsString('Responder', (string) file_get_contents($api->path($webFile->path)));
    }

    public function test_existing_files_are_never_overwritten(): void
    {
        // Arrange
        $project = $this->project();
        new Generator()->make($project, 'service', 'Once');
        $before = (string) file_get_contents($project->path('app/Services/Once.php'));
        file_put_contents($project->path('app/Services/Once.php'), $before . '// edited');

        // Act & Assert
        try {
            new Generator()->make($project, 'service', 'Once');
            self::fail('Expected a CommandFailedException.');
        } catch (CommandFailedException) {
            self::assertStringEndsWith('// edited', (string) file_get_contents($project->path('app/Services/Once.php')));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileNames(): iterable
    {
        yield 'traversal' => ['../../Evil'];
        yield 'nested' => ['Sub/Evil'];
        yield 'lowercase' => ['evil'];
        yield 'injection' => ["Evil{}; system('id');"];
        yield 'dots' => ['Evil.php'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('A', 80)];
        yield 'nul' => ["Evil\0"];
    }

    #[DataProvider('hostileNames')]
    public function test_names_must_be_plain_pascal_case_identifiers(string $name): void
    {
        // Arrange
        $project = $this->project();

        // Act & Assert
        $this->expectException(CommandFailedException::class);
        new Generator()->make($project, 'service', $name);
    }

    public function test_unknown_generators_are_explained(): void
    {
        // Arrange
        $project = $this->project();

        // Act & Assert
        $this->expectException(CommandFailedException::class);
        $this->expectExceptionMessage('Available: controller, service');
        new Generator()->make($project, 'model', 'User');
    }

    private function project(Profile $profile = Profile::Api): Project
    {
        new ProjectScaffolder()->create('demo', $profile, $this->base . '/demo');

        return new Project($this->base . '/demo', 'demo', $profile->value, $profile->modules() === [] ? [] : []);
    }
}

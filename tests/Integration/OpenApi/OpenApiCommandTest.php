<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\OpenApi;

use PHPUnit\Framework\TestCase;
use Trunk\Console\Input\Input;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;
use Trunk\Http\HttpModule;
use Trunk\OpenApi\Console\OpenApiCommand;
use Trunk\OpenApi\OpenApi;
use Trunk\OpenApi\OpenApiModule;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\OpenApi\BrokenOpenApiModule;
use Trunk\Tests\Fixtures\OpenApi\OpenApiRoutesModule;
use Trunk\Tests\Support\OutputCapture;
use Trunk\Validation\ValidationModule;

final class OpenApiCommandTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/trunk-openapi-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->project);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->project);
    }

    public function test_it_writes_the_document_of_the_current_routes(): void
    {
        // Arrange
        $command = $this->command([OpenApiRoutesModule::class]);
        $capture = new OutputCapture();

        // Act
        $code = $command->handle(Input::fromArgv(['trunk', 'openapi', 'docs/api.json']), $capture->output);
        $document = json_decode((string) file_get_contents($this->project . '/docs/api.json'), true, flags: \JSON_THROW_ON_ERROR);

        // Assert
        self::assertSame(0, $code);
        self::assertStringContainsString('Wrote docs/api.json (6 paths).', $capture->stdout());
        self::assertIsArray($document);
        self::assertSame('3.1.0', $document['openapi']);
    }

    public function test_a_broken_returns_attribute_fails_with_the_route_named(): void
    {
        // Arrange
        $command = $this->command([BrokenOpenApiModule::class]);
        $capture = new OutputCapture();

        // Act
        $code = $command->handle(Input::fromArgv(['trunk', 'openapi']), $capture->output);

        // Assert
        self::assertSame(1, $code);
        self::assertStringContainsString('Route /broken:', $capture->stderr() . $capture->stdout());
        self::assertFileDoesNotExist($this->project . '/openapi.json');
    }

    public function test_only_a_json_file_is_written(): void
    {
        // Arrange
        $capture = new OutputCapture();

        // Act
        $code = $this->command([OpenApiRoutesModule::class])->handle(Input::fromArgv(['trunk', 'openapi', '.env']), $capture->output);

        // Assert
        self::assertSame(1, $code);
        self::assertFileDoesNotExist($this->project . '/.env');
    }

    /**
     * @param list<class-string<\Trunk\Contracts\Module>> $routes
     */
    private function command(array $routes): OpenApiCommand
    {
        $modules = [HttpModule::class, ValidationModule::class, OpenApiModule::class, ...$routes];
        file_put_contents($this->project . '/trunk.php', '<?php return ' . var_export(['name' => 'shop', 'type' => 'api', 'modules' => $modules], true) . ';');
        $runtime = new Runtime(Environment::Local, false, $this->project);

        return new OpenApiCommand(new OpenApi($runtime, new Configuration(['openapi' => ['title' => 'Shop', 'version' => '1.0.0', 'mode' => 'development']])), $runtime);
    }
}

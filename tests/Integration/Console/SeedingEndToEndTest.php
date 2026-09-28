<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * The real `trunk` binary: make:factory reads a mapped entity and writes a factory that uses it;
 * db:seed runs database/seeders/DatabaseSeeder.php against a real database.
 */
final class SeedingEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_a_factory_is_generated_from_a_mapped_entity_and_never_overwritten(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'orm']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['make:entity', 'Product']);

        // Act
        [$code, $out] = $project->trunk(['make:factory', 'Product']);
        $factory = (string) file_get_contents($project->directory . '/app/Factories/ProductFactory.php');
        [$again, , $err] = $project->trunk(['make:factory', 'Product']);
        [$unmapped, , $unmappedErr] = $project->trunk(['make:factory', 'Nope']);

        // Assert
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('app/Factories/ProductFactory.php', $out);
        self::assertStringContainsString('namespace App\\Factories;', $factory);
        self::assertStringContainsString('use App\\Entities\\Product;', $factory);
        self::assertStringContainsString('use Faker\\Factory as FakerFactory;', $factory);
        self::assertStringContainsString('$name = $overrides[\'name\'] ?? null;', $factory);
        self::assertStringContainsString("name: \\is_string(\$name) ? \$name : \$this->faker->word(),", $factory);
        self::assertStringNotContainsString("id: \$overrides", $factory, 'the generated id is never faked');
        self::assertNotSame(0, $again);
        self::assertStringContainsString('app/Factories/ProductFactory.php', $err . $out);
        self::assertNotSame(0, $unmapped);
        self::assertStringContainsString('is not a mapped entity', $unmappedErr . $unmapped);
    }

    public function test_db_seed_runs_the_seeder_file_and_refuses_in_production_without_force(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'orm']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['make:entity', 'Product']);
        $project->trunk(['make:factory', 'Product']);
        $project->trunk(['make:migration', 'create_products_table']);
        $migration = (glob($project->directory . '/database/migrations/*.php') ?: [])[0] ?? '';
        file_put_contents($migration, "<?php\n\ndeclare(strict_types=1);\n\nuse Trunk\\Database\\Migration\\Migration;\nuse Trunk\\Database\\Schema\\Blueprint;\nuse Trunk\\Database\\Schema\\Schema;\n\nreturn new Migration(\n    up: function (Schema \$schema): void {\n        \$schema->create('products', function (Blueprint \$t): void {\n            \$t->id();\n            \$t->string('name');\n        });\n    },\n    down: function (Schema \$schema): void {\n        \$schema->dropIfExists('products');\n    },\n);\n");
        $project->trunk(['migrate']);
        file_put_contents($project->directory . '/database/seeders/DatabaseSeeder.php', "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Factories\\ProductFactory;\nuse Trunk\\Orm\\UnitOfWork\\EntityManager;\n\nreturn static function (EntityManager \$manager): void {\n    \$factory = new ProductFactory();\n\n    for (\$i = 0; \$i < 3; \$i++) {\n        \$manager->persist(\$factory->make());\n    }\n\n    \$manager->flush();\n};\n");
        file_put_contents($project->directory . '/app/Controllers/ProductController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse App\\Entities\\Product;\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Http\\Response\\ResponseBuilder;\nuse Trunk\\Orm\\UnitOfWork\\EntityManager;\n\nfinal readonly class ProductController\n{\n    public function __construct(private EntityManager \$orm, private ResponseBuilder \$responses) {}\n\n    public function index(): ResponseInterface\n    {\n        \$products = \$this->orm->repository(Product::class)->query()->orderBy('id')->get();\n\n        return \$this->responses->json(array_map(\$this->orm->toArray(...), \$products));\n    }\n}\n");
        $routes = (string) file_get_contents($project->directory . '/routes/api.php');
        file_put_contents($project->directory . '/routes/api.php', str_replace("        \$api->get('/customers', ", "        \$api->get('/products', [\\App\\Controllers\\ProductController::class, 'index']);\n        \$api->get('/customers', ", $routes));

        // Act
        [$buildCode, $buildOut] = $project->trunk(['build']);
        [$missing, , $missingErr] = $project->trunk(['db:seed'], null, ['APP_ENV' => 'production']);
        [$seedCode, $seedOut] = $project->trunk(['db:seed']);
        [$forcedCode, $forcedOut] = $project->trunk(['db:seed', '--force'], null, ['APP_ENV' => 'production']);
        [, $list] = $project->request('GET', '/api/products', 'local');
        unlink($project->directory . '/database/seeders/DatabaseSeeder.php');
        [$noFile, , $noFileErr] = $project->trunk(['db:seed']);

        // Assert
        self::assertSame(0, $buildCode, $buildOut);
        self::assertNotSame(0, $missing, 'production refuses without --force');
        self::assertStringContainsString('disabled in production', $missingErr . $missing);
        self::assertSame(0, $seedCode, $seedOut);
        self::assertSame(0, $forcedCode, $forcedOut);
        self::assertStringContainsString('"id":1,', $list);
        self::assertStringContainsString('"id":6,', $list, '--force ran the seeder again in production, 3 more rows');
        self::assertNotSame(0, $noFile);
        self::assertStringContainsString('does not exist', $noFileErr . $noFile);
    }
}

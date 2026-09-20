<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * The real `trunk` binary and public/index.php: enable the capabilities, generate an entity, migrate,
 * then read and write it over HTTP in development and from the compiled build in production.
 */
final class OrmEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_entities_are_generated_migrated_built_and_served_in_both_modes(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'orm']);
        $project->trunk(['package:install', 'console']);

        // Act
        [$makeCode, $makeOut] = $project->trunk(['make:entity', 'Product']);
        [$overwriteCode] = $project->trunk(['make:entity', 'Product']);
        [$badCode] = $project->trunk(['make:entity', '../Evil']);
        $project->trunk(['make:migration', 'create_products_table']);
        $migration = (glob($project->directory . '/database/migrations/*.php') ?: [])[0] ?? '';
        file_put_contents($migration, "<?php\n\ndeclare(strict_types=1);\n\nuse Trunk\\Database\\Migration\\Migration;\nuse Trunk\\Database\\Schema\\Blueprint;\nuse Trunk\\Database\\Schema\\Schema;\n\nreturn new Migration(\n    up: function (Schema \$schema): void {\n        \$schema->create('products', function (Blueprint \$t): void {\n            \$t->id();\n            \$t->string('name');\n        });\n    },\n    down: function (Schema \$schema): void {\n        \$schema->dropIfExists('products');\n    },\n);\n");
        [$migrateCode, $migrateOut] = $project->trunk(['migrate']);
        [$validateCode, $validateOut] = $project->trunk(['orm:validate']);
        file_put_contents($project->directory . '/app/Controllers/ProductController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse App\\Entities\\Product;\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Http\\Response\\ResponseBuilder;\nuse Trunk\\Orm\\UnitOfWork\\EntityManager;\n\nfinal readonly class ProductController\n{\n    public function __construct(private EntityManager \$orm, private ResponseBuilder \$responses) {}\n\n    public function create(): ResponseInterface\n    {\n        \$this->orm->persist(new Product(name: 'Widget'));\n        \$this->orm->flush();\n\n        return \$this->responses->json(['ok' => true]);\n    }\n\n    public function index(): ResponseInterface\n    {\n        \$products = \$this->orm->repository(Product::class)->query()->orderBy('id')->get();\n\n        return \$this->responses->json(array_map(\$this->orm->toArray(...), \$products));\n    }\n}\n");
        $routes = (string) file_get_contents($project->directory . '/routes/api.php');
        file_put_contents($project->directory . '/routes/api.php', str_replace("        \$api->get('/customers', ", "        \$api->post('/products', [\\App\\Controllers\\ProductController::class, 'create']);\n        \$api->get('/products', [\\App\\Controllers\\ProductController::class, 'index']);\n        \$api->get('/customers', ", $routes));
        [, $created] = $project->request('POST', '/api/products', 'local');
        [, $developmentList] = $project->request('GET', '/api/products', 'local');
        [$buildCode, $buildOut] = $project->trunk(['build']);
        [, $productionCreated] = $project->request('POST', '/api/products', 'production');
        [, $productionList] = $project->request('GET', '/api/products', 'production');

        // Assert
        self::assertSame(0, $makeCode, $makeOut);
        self::assertNotSame(0, $overwriteCode, 'make:entity never overwrites.');
        self::assertNotSame(0, $badCode);
        self::assertFileDoesNotExist($project->directory . '/app/Evil.php');
        self::assertSame(0, $migrateCode, $migrateOut);
        self::assertSame(0, $validateCode, $validateOut);
        self::assertStringContainsString('1 entity map(s) are valid', $validateOut);
        self::assertSame('{"ok":true}', $created);
        self::assertSame('[{"id":1,"name":"Widget"}]', $developmentList);
        self::assertSame(0, $buildCode, $buildOut);
        self::assertFileExists($project->directory . '/build/orm.php');
        self::assertSame('{"ok":true}', $productionCreated);
        self::assertSame('[{"id":1,"name":"Widget"},{"id":2,"name":"Widget"}]', $productionList);
        self::assertStringNotContainsString('Reflection', (string) file_get_contents($project->directory . '/build/orm.php'));
    }

    public function test_an_entity_and_its_map_are_written_to_separate_folders_and_never_overwritten(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'orm']);
        $project->trunk(['package:install', 'console']);
        $entityPath = $project->directory . '/app/Entities/Product.php';
        $mapPath = $project->directory . '/app/Orm/ProductMap.php';

        // Act
        [$code, $out] = $project->trunk(['make:entity', 'Product']);
        $entity = (string) file_get_contents($entityPath);
        $map = (string) file_get_contents($mapPath);
        file_put_contents($entityPath, $entity . "// edited\n");
        [$again, , $againErr] = $project->trunk(['make:entity', 'Product']);
        unlink($mapPath);
        [$halfway] = $project->trunk(['make:entity', 'Product']);

        // Assert
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('app/Entities/Product.php', $out);
        self::assertStringContainsString('app/Orm/ProductMap.php', $out);
        self::assertStringContainsString('namespace App\\Entities;', $entity);
        self::assertStringContainsString('namespace App\\Orm;', $map);
        self::assertStringContainsString('use App\\Entities\\Product;', $map);
        self::assertStringContainsString('return Product::class;', $map);
        self::assertFileDoesNotExist($project->directory . '/app/Orm/Product.php', 'the entity is not written next to the map');
        self::assertNotSame(0, $again);
        self::assertStringContainsString('app/Entities/Product.php', $againErr . $out, 'the refusal names the file that exists');
        self::assertStringEndsWith("// edited\n", (string) file_get_contents($entityPath), 'an existing entity is never overwritten');
        self::assertNotSame(0, $halfway, 'a lone entity blocks the command too');
        self::assertFileDoesNotExist($mapPath);
    }

    public function test_the_orm_install_creates_both_folders(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');

        // Act
        $project->trunk(['package:install', 'orm']);

        // Assert
        self::assertDirectoryExists($project->directory . '/app/Entities');
        self::assertDirectoryExists($project->directory . '/app/Orm');
    }

    public function test_a_project_that_keeps_its_entity_next_to_the_map_still_validates_and_builds(): void
    {
        // Arrange: the layout make:entity used before entities had their own folder
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'orm']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['make:entity', 'Product']);
        $entity = (string) file_get_contents($project->directory . '/app/Entities/Product.php');
        file_put_contents($project->directory . '/app/Orm/Product.php', str_replace('namespace App\\Entities;', 'namespace App\\Orm;', $entity));
        unlink($project->directory . '/app/Entities/Product.php');
        $map = (string) file_get_contents($project->directory . '/app/Orm/ProductMap.php');
        file_put_contents($project->directory . '/app/Orm/ProductMap.php', str_replace("use App\\Entities\\Product;\n", '', $map));

        // Act
        [$validateCode, $validateOut] = $project->trunk(['orm:validate']);
        [$buildCode, $buildOut] = $project->trunk(['build']);

        // Assert
        self::assertSame(0, $validateCode, $validateOut);
        self::assertSame(0, $buildCode, $buildOut);
        self::assertFileExists($project->directory . '/build/orm.php');
    }

    public function test_a_broken_map_fails_orm_validate_and_the_build_without_leaving_a_partial_build(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'orm']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['make:entity', 'Product']);
        $map = (string) file_get_contents($project->directory . '/app/Orm/ProductMap.php');
        file_put_contents($project->directory . '/app/Orm/ProductMap.php', str_replace("\$map->table('products');", "\$map->table('products; DROP TABLE x');", $map));

        // Act
        [$validateCode, $validateOut, $validateErr] = $project->trunk(['orm:validate']);
        [$buildCode] = $project->trunk(['build']);

        // Assert
        self::assertNotSame(0, $validateCode);
        self::assertStringContainsString('not a plain SQL name', $validateOut . $validateErr);
        self::assertNotSame(0, $buildCode);
        self::assertFileDoesNotExist($project->directory . '/build/orm.php');
    }
}

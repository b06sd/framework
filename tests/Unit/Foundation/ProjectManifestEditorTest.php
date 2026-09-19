<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Exception\ProjectException;
use Trunk\Foundation\Project\ProjectManifestEditor;
use Trunk\Support\Directory;

final class ProjectManifestEditorTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/trunk-editor-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->base);
    }

    public function test_the_module_list_is_read_in_every_plain_spelling(): void
    {
        // Arrange
        $this->manifest("[\n        \\Trunk\\Http\\HttpModule::class,\n        Trunk\\Tusk\\TuskModule::class,\n        'Trunk\\\\Mvc\\\\MvcModule',\n        App\\AppModule::class\n    ]");

        // Act
        $modules = new ProjectManifestEditor()->read($this->base);

        // Assert
        self::assertSame(['Trunk\\Http\\HttpModule', 'Trunk\\Tusk\\TuskModule', 'Trunk\\Mvc\\MvcModule', 'App\\AppModule'], $modules);
    }

    public function test_only_the_module_list_is_rewritten_and_everything_else_is_preserved(): void
    {
        // Arrange
        $this->manifest("[\n        \\A\\One::class,\n    ]", "// keep this comment\n", "    // and this one\n");
        $editor = new ProjectManifestEditor();

        // Act
        $editor->write($this->base, ['A\\One', 'B\\Two']);
        $source = (string) file_get_contents($this->base . '/trunk.php');

        // Assert
        self::assertStringContainsString("// keep this comment\n", $source);
        self::assertStringContainsString("    // and this one\n", $source);
        self::assertStringContainsString("'extra' => 'kept',", $source);
        self::assertStringContainsString("        \\A\\One::class,\n        \\B\\Two::class,\n    ],", $source);
        self::assertSame(['A\\One', 'B\\Two'], $editor->read($this->base));
    }

    public function test_writing_is_idempotent_and_removes_duplicates(): void
    {
        // Arrange
        $this->manifest('[]');
        $editor = new ProjectManifestEditor();

        // Act
        $editor->write($this->base, ['A\\One', 'A\\One', 'B\\Two']);
        $once = (string) file_get_contents($this->base . '/trunk.php');
        $editor->write($this->base, $editor->read($this->base));

        // Assert
        self::assertSame($once, file_get_contents($this->base . '/trunk.php'));
        self::assertSame(['A\\One', 'B\\Two'], $editor->read($this->base));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unsupported(): iterable
    {
        yield 'comment inside list' => ["[\n        \\A\\One::class, // note\n    ]", '', 'not a plain class name'];
        yield 'spread' => ['[...$others]', '', 'not a plain class name'];
        yield 'function call' => ["[foo()]", '', 'not a plain class name'];
        yield 'imports' => ['[One::class]', "use A\\One;\n", '"namespace" or "use"'];
        yield 'namespace' => ['[]', "namespace App;\n", '"namespace" or "use"'];
    }

    #[DataProvider('unsupported')]
    public function test_anything_that_is_not_a_plain_list_of_class_names_is_refused_not_guessed(string $literal, string $before, string $reason): void
    {
        // Arrange
        $this->manifest($literal, $before);
        $original = (string) file_get_contents($this->base . '/trunk.php');
        $editor = new ProjectManifestEditor();

        // Act
        try {
            $editor->write($this->base, ['A\\One']);
            self::fail('Expected a ProjectException.');
        } catch (ProjectException $e) {
            // Assert
            self::assertStringContainsString($reason, $e->getMessage());
            self::assertSame($original, file_get_contents($this->base . '/trunk.php'));
        }
    }

    public function test_a_file_without_a_module_list_and_invalid_php_are_refused(): void
    {
        // Arrange
        file_put_contents($this->base . '/trunk.php', "<?php\nreturn ['name' => 'x'];\n");
        $editor = new ProjectManifestEditor();
        $base = $this->base;
        $message = static function (callable $attempt): string {
            try {
                $attempt();
            } catch (ProjectException $e) {
                return $e->getMessage();
            }

            return '';
        };

        // Act
        $noList = $message(static fn() => $editor->read($base));
        file_put_contents($this->base . '/trunk.php', '<?php return [;');
        $invalid = $message(static fn() => $editor->read($base));

        // Assert
        self::assertStringContainsString("no plain 'modules'", $noList);
        self::assertStringContainsString('not valid PHP', $invalid);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileNames(): iterable
    {
        yield 'code injection' => ["Foo::class, system('id'), Bar"];
        yield 'quote breakout' => ["A'); system('id'); //"];
        yield 'traversal' => ['A\\..\\..\\B'];
        yield 'space' => ['A B'];
        yield 'empty' => [''];
        yield 'nul' => ["A\0"];
    }

    #[DataProvider('hostileNames')]
    public function test_class_names_are_validated_so_the_editor_can_never_write_code(string $name): void
    {
        // Arrange
        $this->manifest('[]');
        $original = (string) file_get_contents($this->base . '/trunk.php');

        // Act & Assert
        try {
            new ProjectManifestEditor()->write($this->base, [$name]);
            self::fail('Expected a ProjectException.');
        } catch (ProjectException) {
            self::assertSame($original, file_get_contents($this->base . '/trunk.php'));
        }
    }

    private function manifest(string $modulesLiteral, string $before = '', string $after = ''): void
    {
        file_put_contents($this->base . '/trunk.php', "<?php\n\ndeclare(strict_types=1);\n" . $before . "\nreturn [\n    'name' => 'demo',\n    'type' => 'web',\n    'modules' => " . $modulesLiteral . ",\n    'extra' => 'kept',\n" . $after . "];\n");
    }
}

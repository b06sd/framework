<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Exception\ProjectException;
use Trunk\Foundation\Project\EnvironmentFile;
use Trunk\Support\Directory;

final class EnvironmentFileTest extends TestCase
{
    public function test_the_supported_syntax_is_parsed(): void
    {
        // Arrange
        $contents = <<<'ENV'
            # a comment
            APP_ENV=local
            export APP_DEBUG=1
            APP_NAME="My \"Great\" App"
            LITERAL='no $expansion \n here'
            SPACED = value with spaces   # trailing comment
            EMPTY=
            URL=https://example.test/path#anchor
            ENV;

        // Act
        $variables = new EnvironmentFile()->parse($contents);

        // Assert
        self::assertSame([
            'APP_ENV' => 'local',
            'APP_DEBUG' => '1',
            'APP_NAME' => 'My "Great" App',
            'LITERAL' => 'no $expansion \n here',
            'SPACED' => 'value with spaces',
            'EMPTY' => '',
            'URL' => 'https://example.test/path#anchor',
        ], $variables);
    }

    public function test_there_is_no_interpolation_so_values_never_expand(): void
    {
        // Arrange
        $contents = "A=one\nB=\${A}-two\nC=\"\$(id)\"\n";

        // Act
        $variables = new EnvironmentFile()->parse($contents);

        // Assert
        self::assertSame('${A}-two', $variables['B']);
        self::assertSame('$(id)', $variables['C']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformed(): iterable
    {
        yield 'no equals' => ["JUST_A_WORD\n", 'line 1'];
        yield 'bad key' => ["1BAD=x\n", 'line 1'];
        yield 'key with space' => ["A B=x\n", 'line 1'];
        yield 'unterminated double quote' => ["OK=1\nA=\"never closed\n", 'line 2'];
        yield 'unterminated single quote' => ["A='never closed\n", 'line 1'];
        yield 'text after closing quote' => ["A=\"x\" y\n", 'line 1'];
        yield 'nul byte' => ["A=x\0y\n", 'line 1'];
    }

    #[DataProvider('malformed')]
    public function test_malformed_lines_are_reported_with_their_line_number(string $contents, string $expected): void
    {
        // Arrange
        $file = new EnvironmentFile();

        // Act & Assert
        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage($expected);
        $file->parse($contents);
    }

    public function test_an_oversized_file_is_refused(): void
    {
        // Arrange
        $file = new EnvironmentFile();

        // Act & Assert
        $this->expectException(ProjectException::class);
        $file->parse(str_repeat("A=1\n", 20000));
    }

    public function test_real_environment_variables_override_the_file_and_the_file_is_optional(): void
    {
        // Arrange
        $base = sys_get_temp_dir() . '/trunk-env-' . bin2hex(random_bytes(4));
        mkdir($base);
        $loader = new EnvironmentFile();

        // Act
        $withoutFile = $loader->load($base, ['APP_ENV' => 'production']);
        file_put_contents($base . '/.env', "APP_ENV=local\nAPP_PORT=8006\n");
        $merged = $loader->load($base, ['APP_ENV' => 'production']);
        $exists = $loader->exists($base);
        new Directory()->remove($base);

        // Assert
        self::assertSame(['APP_ENV' => 'production'], $withoutFile);
        self::assertEquals(['APP_PORT' => '8006', 'APP_ENV' => 'production'], $merged);
        self::assertTrue($exists);
    }
}

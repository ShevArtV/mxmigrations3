<?php

namespace MxMigrations\Tests\Unit\Recipe;

use MxMigrations\Core\MigrationWriter;
use MxMigrations\Recipe\MigrationGenerator;
use MxMigrations\Recipe\RecipeRegistry;
use MxMigrations\Tests\Fixtures\ProjectRecipeProvider;
use PHPUnit\Framework\TestCase;

final class MigrationGeneratorTest extends TestCase
{
    public function testWritesProjectRecipeToConfiguredDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/mxmigrations-generator-' . uniqid('', true);
        $registry = new RecipeRegistry();
        $registry->addProvider(new ProjectRecipeProvider());
        $writer = new MigrationWriter($dir, static function (): int {
            return strtotime('2026-08-10 17:00:00');
        }, 'UTC');

        $result = (new MigrationGenerator($writer, $registry))->generate(
            'seed_widget',
            'project:seed-widget',
            ['widget' => 'hero'],
            true
        );

        self::assertSame('20260810_1700__seed_widget.php', $result['name']);
        self::assertFileExists((string)$result['path']);
        self::assertStringContainsString("'hero'", (string)file_get_contents((string)$result['path']));
        unlink((string)$result['path']);
        rmdir($dir);
    }
}

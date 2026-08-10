<?php

namespace MxMigrations\Tests\Fixtures;

use MxMigrations\Recipe\MigrationRecipeInterface;

final class ProjectRecipe implements MigrationRecipeInterface
{
    public function getName(): string { return 'project:seed-widget'; }
    public function getDescription(): string { return 'проектная миграция тестового виджета'; }
    public function getOptions(): array { return ['widget' => 'имя виджета']; }

    public function render(string $migrationName, array $arguments): string
    {
        $widget = trim((string)($arguments['widget'] ?? ''));
        if ($widget === '') {
            throw new \InvalidArgumentException('Для проектного рецепта нужен --widget.');
        }

        return "<?php\n\nreturn function (\MODX\Revolution\modX \$modx): void {\n"
            . '    $widget = ' . var_export($widget, true) . ";\n"
            . "    echo 'project widget: ' . \$widget . PHP_EOL;\n};\n";
    }
}

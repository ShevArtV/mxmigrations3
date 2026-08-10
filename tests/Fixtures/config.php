<?php

return [
    'id' => 'fixture',
    'modx_root' => dirname(__DIR__, 2),
    'migrations_path' => __DIR__ . '/migrations',
    'autoload' => [dirname(__DIR__, 2) . '/vendor/autoload.php'],
    'recipe_providers' => [MxMigrations\Tests\Fixtures\ProjectRecipeProvider::class],
];

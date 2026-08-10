<?php

$root = dirname(__DIR__, 2);

return [
    'id' => 'site',
    'modx_root' => $root,
    'migrations_path' => $root . '/core/migrations',
    'models' => [
        'site' => [
            'schema_path' => $root . '/core/components/site/schema/site.mysql.schema.xml',
            'model_path' => $root . '/core/components/site/src/Model',
        ],
        'minishop3' => [
            'schema_path' => $root . '/core/components/minishop3/schema/minishop3.mysql.schema.xml',
            'model_path' => $root . '/core/components/minishop3/src/Model',
            'overlay' => true,
        ],
    ],
    'autoload' => [$root . '/core/components/site/vendor/autoload.php'],
    'recipe_providers' => [Site\Migrations\RecipeProvider::class],
];

<?php

/**
 * Конфигурация transport-пакета mxMigrations для MODX 3.
 *
 * MODX 2 живёт в отдельном репозитории mxmigrations и нумеруется 1.x;
 * здесь мажор означает платформу — 2.x = MODX 3.
 */

return [
    'name' => 'mxMigrations',
    'name_lower' => 'mxmigrations',
    'name_short' => 'mxmigrations',
    'version' => '2.0.0',
    'release' => 'beta',
    'php_version' => '8.1',

    'paths' => [
        'core' => 'core/components/mxmigrations/',
        'assets' => 'assets/components/mxmigrations/',
    ],

    'elements' => [
        'category' => 'mxMigrations',
    ],

    'static' => [
        'chunks' => false,
        'snippets' => false,
        'plugins' => false,
    ],

    'encrypt' => false,

    'tools' => [
        // PHPUnit установлен в корне dev-проекта, а ToolsChecker запускается из
        // core/components/mxmigrations. Тесты гоняются отдельным `composer test`.
        'test' => '',
        'analyse' => '',
        'cs' => '',
        'csMode' => 'fix',
    ],

    'build' => [
        'download' => false,
        'install' => false,
    ],
];

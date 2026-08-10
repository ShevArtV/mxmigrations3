<?php

$root = getenv('MXMIGRATIONS_SMOKE_ROOT');
if (!$root || !is_file($root . '/index.php')) {
    throw new RuntimeException('MXMIGRATIONS_SMOKE_ROOT does not point to MODX.');
}
define('MODX_API_MODE', true);
require $root . '/index.php';
$prefix = (string)$modx->getOption('table_prefix', null, 'modx_');
if ($modx->exec('DROP TABLE IF EXISTS `' . $prefix . 'mxmigrations_smoke_migrations`') === false) {
    throw new RuntimeException('Smoke journal cleanup failed.');
}
echo 'Smoke journal removed.' . PHP_EOL;

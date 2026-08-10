<?php

$root = getenv('MXMIGRATIONS_SMOKE_ROOT');
if (!$root) {
    throw new RuntimeException('MXMIGRATIONS_SMOKE_ROOT is required.');
}

return [
    'id' => 'mxmigrations_smoke',
    'modx_root' => $root,
    'migrations_path' => __DIR__ . '/migrations',
];

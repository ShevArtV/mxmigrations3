<?php

namespace MxMigrations\Tests\Unit\Model;

use MxMigrations\Config\ProjectConfig;
use MxMigrations\Model\SchemaCoordinator;
use PHPUnit\Framework\TestCase;

final class SchemaCoordinatorTest extends TestCase
{
    public function testStoresThirdPartyChangeWithoutEditingItsSchema(): void
    {
        $root = sys_get_temp_dir() . '/mxmigrations-coordinator-' . uniqid('', true);
        mkdir($root . '/migrations', 0775, true);
        $schemaPath = $root . '/minishop.xml';
        $schema = '<?xml version="1.0"?><model package="minishop2">'
            . '<object class="msOrder" table="ms2_orders"><field key="id" dbtype="int" phptype="integer" /></object>'
            . '</model>';
        file_put_contents($schemaPath, $schema);
        $config = new ProjectConfig([
            'id' => 'site', 'modx_root' => $root, 'migrations_path' => $root . '/migrations',
            'models' => ['minishop2' => [
                'schema_path' => $schemaPath, 'model_path' => $root . '/model', 'overlay' => true,
            ]],
        ], $root);

        $coordinator = new SchemaCoordinator($config);
        $prepared = $coordinator->prepare('20260810_1200__external_id.php', 'add-column', [
            'table' => 'ms2_orders', 'column' => 'external_id', 'type' => 'VARCHAR(64) NULL',
        ]);
        self::assertNotNull($prepared);
        $coordinator->commit($prepared);

        self::assertSame($schema, file_get_contents($schemaPath));
        self::assertFileExists($config->getModelChangesPath());
        self::assertSame('minishop2', $coordinator->getStore()->all()[0]['model']);
        self::assertNotNull($coordinator->prepare('20260810_1201__external_id_index.php', 'add-index', [
            'table' => 'ms2_orders', 'name' => 'external_id', 'columns' => 'external_id',
        ]));

        unlink($config->getModelChangesPath());
        rmdir(dirname($config->getModelChangesPath()));
        unlink($schemaPath); rmdir($root . '/migrations'); rmdir($root);
    }
}

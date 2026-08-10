<?php

namespace MxMigrations\Tests\Unit\Model;

use MxMigrations\Model\SchemaDocument;
use PHPUnit\Framework\TestCase;

final class SchemaDocumentTest extends TestCase
{
    public function testAppliesColumnAndIndexChanges(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mxmigrations-schema-');
        file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<model package="site" version="1.1"><object class="SiteOrder" table="site_orders" extends="xPDOSimpleObject"><field key="id" dbtype="int" phptype="integer" null="false" /></object></model>
XML
        );

        $schema = new SchemaDocument($path);
        self::assertSame('SiteOrder', $schema->classForTable('site_orders'));
        $schema->apply([
            'class' => 'SiteOrder', 'recipe' => 'add-column', 'column' => 'status',
            'type' => 'VARCHAR(20) NOT NULL DEFAULT "new"',
        ]);
        $schema->apply([
            'class' => 'SiteOrder', 'recipe' => 'add-index', 'name' => 'status',
            'columns' => ['status'], 'unique' => false,
        ]);
        $schema->save($path);

        $xml = (string)file_get_contents($path);
        self::assertStringContainsString('key="status"', $xml);
        self::assertStringContainsString('alias="status"', $xml);
        unlink($path);
    }
}


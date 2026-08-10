<?php

namespace MxMigrations\Tests\Unit\Model;

use MxMigrations\Model\ModelFilesystem;
use PHPUnit\Framework\TestCase;

final class ModelFilesystemTest extends TestCase
{
    public function testKeepsExistingClassAndUpdatesManagedMap(): void
    {
        $root = sys_get_temp_dir() . '/mxmigrations-files-' . uniqid('', true);
        $current = $root . '/current';
        $built = $root . '/built';
        mkdir($current . '/mysql', 0775, true);
        mkdir($built . '/mysql', 0775, true);
        file_put_contents($current . '/Order.php', 'custom logic');
        file_put_contents($built . '/Order.php', 'generated stub');
        file_put_contents($current . '/mysql/order.map.inc.php', 'old map');
        file_put_contents($built . '/mysql/order.map.inc.php', 'new map');
        $managed = static function (string $relative): bool {
            return substr($relative, -12) === '.map.inc.php';
        };

        $files = new ModelFilesystem();
        $diff = $files->diff($current, $built, $managed);
        self::assertSame(['mysql/order.map.inc.php'], $diff['changed']);
        $files->apply($current, $built, $managed);
        self::assertSame('custom logic', file_get_contents($current . '/Order.php'));
        self::assertSame('new map', file_get_contents($current . '/mysql/order.map.inc.php'));

        unlink($current . '/Order.php');
        unlink($built . '/Order.php');
        unlink($current . '/mysql/order.map.inc.php');
        unlink($built . '/mysql/order.map.inc.php');
        rmdir($current . '/mysql'); rmdir($built . '/mysql'); rmdir($current); rmdir($built); rmdir($root);
    }
}

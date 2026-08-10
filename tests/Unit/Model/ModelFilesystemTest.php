<?php

namespace MxMigrations\Tests\Unit\Model;

use MxMigrations\Model\ModelFilesystem;
use PHPUnit\Framework\TestCase;

final class ModelFilesystemTest extends TestCase
{
    public function testKeepsExistingDomainClassCreatesMissingOneAndUpdatesPlatformModel(): void
    {
        $root = sys_get_temp_dir() . '/mxmigrations-files-' . uniqid('', true);
        $current = $root . '/current';
        $built = $root . '/built';
        mkdir($current . '/mysql', 0775, true);
        mkdir($built . '/mysql', 0775, true);
        file_put_contents($current . '/Order.php', 'existing domain class');
        file_put_contents($built . '/Order.php', 'regenerated domain class');
        file_put_contents($built . '/Product.php', 'new domain class');
        file_put_contents($current . '/mysql/order.map.inc.php', 'old map');
        file_put_contents($built . '/mysql/order.map.inc.php', 'new map');
        $managed = static function (string $relative): bool {
            return strpos(str_replace('\\', '/', $relative), 'mysql/') === 0;
        };

        $files = new ModelFilesystem();
        $diff = $files->diff($current, $built, $managed);
        self::assertSame(['Product.php'], $diff['added']);
        self::assertSame(['mysql/order.map.inc.php'], $diff['changed']);
        $files->apply($current, $built, $managed);
        self::assertSame('existing domain class', file_get_contents($current . '/Order.php'));
        self::assertSame('new domain class', file_get_contents($current . '/Product.php'));
        self::assertSame('new map', file_get_contents($current . '/mysql/order.map.inc.php'));

        unlink($current . '/Order.php');
        unlink($current . '/Product.php');
        unlink($built . '/Order.php');
        unlink($built . '/Product.php');
        unlink($current . '/mysql/order.map.inc.php');
        unlink($built . '/mysql/order.map.inc.php');
        rmdir($current . '/mysql'); rmdir($built . '/mysql'); rmdir($current); rmdir($built); rmdir($root);
    }
}

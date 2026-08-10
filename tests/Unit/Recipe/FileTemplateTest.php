<?php

namespace MxMigrations\Tests\Unit\Recipe;

use MxMigrations\Recipe\FileTemplate;
use PHPUnit\Framework\TestCase;

final class FileTemplateTest extends TestCase
{
    public function testRendersNamedPlaceholders(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mxmigrations-template-');
        self::assertNotFalse($path);
        file_put_contents($path, '<?php $code = {{warehouse}}; // {{migration_name}}');

        try {
            self::assertSame(
                "<?php \$code = 'main'; // 20260810_1700__warehouse.php",
                FileTemplate::render($path, [
                    'warehouse' => var_export('main', true),
                    'migration_name' => '20260810_1700__warehouse.php',
                ])
            );
        } finally {
            unlink($path);
        }
    }

    public function testRejectsMissingPlaceholder(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mxmigrations-template-');
        self::assertNotFalse($path);
        file_put_contents($path, '{{required}}');

        try {
            $this->expectException(\InvalidArgumentException::class);
            FileTemplate::render($path, []);
        } finally {
            unlink($path);
        }
    }

    public function testRejectsUnusedData(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mxmigrations-template-');
        self::assertNotFalse($path);
        file_put_contents($path, '{{used}}');

        try {
            $this->expectException(\InvalidArgumentException::class);
            FileTemplate::render($path, ['used' => 'yes', 'typo' => 'no']);
        } finally {
            unlink($path);
        }
    }
}

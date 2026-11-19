<?php

namespace MxMigrations\Tests\Unit\Core;

use MxMigrations\Core\MigrationRunner;
use MxMigrations\Tests\Fixtures\FakeExecutor;
use MxMigrations\Tests\Fixtures\FakeJournal;
use MxMigrations\Tests\Fixtures\FakeLock;
use PHPUnit\Framework\TestCase;

/** Общая обвязка тестов раннера: временный каталог миграций и заглушки. */
abstract class RunnerTestCase extends TestCase
{
    protected string $dir;
    protected FakeJournal $journal;
    protected FakeExecutor $executor;
    protected FakeLock $lock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mxm-case-' . uniqid('', true);
        mkdir($this->dir);
        $this->journal = new FakeJournal();
        $this->executor = new FakeExecutor();
        $this->lock = new FakeLock();
    }

    protected function tearDown(): void
    {
        foreach ((array)glob($this->dir . '/*') as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    protected function runner(bool $strictOrder = false): MigrationRunner
    {
        return new MigrationRunner($this->dir, $this->journal, $this->executor, $this->lock, null, $strictOrder);
    }

    /** Миграция с уникальным содержимым и, при необходимости, аннотациями в шапке. */
    protected function write(string $name, string $header = ''): void
    {
        file_put_contents(
            $this->dir . '/' . $name,
            "<?php\n" . ($header !== '' ? "/**\n" . $header . "\n */\n" : '') . "// " . $name . "\nreturn function (\$modx) {};\n"
        );
    }
}

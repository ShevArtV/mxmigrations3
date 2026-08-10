<?php

/**
 * Тесты выбора метки для новой миграции (#2608-40).
 *
 * Проверяется главное свойство: метка новой миграции больше всех имеющихся в
 * каталоге. Без него генератор сам создавал бы миграции «из прошлого» — часы
 * машины в UTC, а метки в каталоге по московскому времени.
 */

namespace MxMigrations\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use MxMigrations\Recipe\BuiltinTemplates;
use MxMigrations\Core\MigrationWriter;

class MigrationWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sg-writer-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array)scandir($this->dir) as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                unlink($this->dir . '/' . $entry);
            }
        }
        rmdir($this->dir);
    }

    public function testUsesCurrentTimeWhenDirectoryIsEmpty(): void
    {
        $writer = $this->writerAt('2026-08-07 10:30:00');

        $this->assertSame('20260807_1030__do_something.php', $writer->nextName('do_something'));
    }

    public function testStepsOverExistingLabelInTheSameMinute(): void
    {
        $this->write('20260807_1030__first.php');
        $writer = $this->writerAt('2026-08-07 10:30:00');

        $this->assertSame('20260807_1031__second.php', $writer->nextName('second'));
    }

    /**
     * Часы машины отстают от последней метки — берём не время, а метку.
     *
     * Ровно этот случай и есть на проекте: консольный PHP в UTC, а последние
     * миграции помечены по московскому времени.
     */
    public function testNeverGoesBeforeTheLastLabel(): void
    {
        $this->write('20260806_2100__applied.php');
        $writer = $this->writerAt('2026-08-06 19:08:00');

        $this->assertSame('20260806_2101__next.php', $writer->nextName('next'));
    }

    /** В каталоге есть `20260806_2400` — 24:00 не существует, переполнение нормализуется. */
    public function testHandlesLabelWithImpossibleTime(): void
    {
        $this->write('20260806_2400__leftover.php');
        $writer = $this->writerAt('2026-08-06 19:08:00');

        $this->assertSame('20260807_0001__next.php', $writer->nextName('next'));
    }

    /** Baseline-файлы без метки в расчёте не участвуют. */
    public function testIgnoresFilesOutsideConvention(): void
    {
        $this->write('add_order_num_counter.sql');
        $this->write('fix_header_menu_structure.php');
        $writer = $this->writerAt('2026-08-07 10:30:00');

        $this->assertNull($writer->lastLabel());
        $this->assertSame('20260807_1030__next.php', $writer->nextName('next'));
    }

    public function testWriteCreatesFileAndReturnsPath(): void
    {
        $writer = $this->writerAt('2026-08-07 10:30:00');

        $path = $writer->write('do_something', '<?php // тело');

        $this->assertFileExists($path);
        $this->assertSame('20260807_1030__do_something.php', basename($path));
        $this->assertSame('<?php // тело', file_get_contents($path));
    }

    public function testTwoWritesInTheSameMinuteDoNotOverwriteEachOther(): void
    {
        $writer = $this->writerAt('2026-08-07 10:30:00');

        $first = $writer->write('first', 'один');
        $second = $writer->write('second', 'два');

        $this->assertSame('20260807_1030__first.php', basename($first));
        $this->assertSame('20260807_1031__second.php', basename($second));
        $this->assertSame('один', file_get_contents($first));
    }

    public function testNormalizesSlug(): void
    {
        $this->assertSame('drop_stale_orders', MigrationWriter::normalizeSlug('Drop Stale Orders'));
        $this->assertSame('drop_stale_orders', MigrationWriter::normalizeSlug('drop-stale-orders'));
    }

    public function testRejectsSlugWithForeignCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MigrationWriter::normalizeSlug('удалить_заказы');
    }

    public function testRejectsEmptySlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MigrationWriter::normalizeSlug('   ');
    }

    /**
     * Вторая операция дописывается в тот же файл: единица миграции — одна причина
     * изменения, а не одно изменение базы.
     */
    public function testAppendMergesSecondOperationIntoTheSameFile(): void
    {
        $writer = $this->writerAt('2026-08-07 10:30:00');
        $templates = new BuiltinTemplates();

        $path = $writer->write('two_fixes', $templates->render('add-column', 'x.php', [
            'table' => 'sleepandglow_reviews',
            'column' => 'is_pinned',
            'type' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ]));

        $writer->append($path, $templates->render('add-index', 'x.php', [
            'table' => 'sleepandglow_reviews',
            'name' => 'idx_pinned',
            'columns' => 'is_pinned',
        ]), 'add-index: idx_pinned');

        $merged = (string)file_get_contents($path);

        $this->assertSame(1, substr_count($merged, 'return function'), 'функция должна остаться одна');
        $this->assertStringContainsString('ADD COLUMN', $merged);
        $this->assertStringContainsString('ADD INDEX', $merged);
        $this->assertStringContainsString('// --- add-index: idx_pinned ---', $merged);
        $this->assertPhpIsValid($path);
    }

    /** `use` из дописанной операции переезжает в шапку и не дублируется. */
    public function testAppendMovesUseStatementsToTheHeader(): void
    {
        $writer = $this->writerAt('2026-08-07 10:30:00');
        $templates = new BuiltinTemplates();

        $path = $writer->write('cleanup', $templates->render('drop-tables', 'x.php', ['tables' => 'old_one']));
        $custom = <<<'PHP'
<?php

use Vendor\ProjectMigrationHelper;

return function (\MODX\Revolution\modX $modx): void {
    ProjectMigrationHelper::run($modx);
};
PHP;
        $writer->append($path, $custom);
        $writer->append($path, $custom);

        $merged = (string)file_get_contents($path);

        $this->assertSame(1, substr_count($merged, 'use Vendor\ProjectMigrationHelper;'));
        $this->assertLessThan(
            (int)strpos($merged, 'return function'),
            (int)strpos($merged, 'use Vendor\ProjectMigrationHelper;')
        );
        $this->assertPhpIsValid($path);
    }

    private function assertPhpIsValid(string $path): void
    {
        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, 'Склеенный файл невалиден: ' . implode("\n", $output));
    }

    private function writerAt(string $moment): MigrationWriter
    {
        $timestamp = (int)strtotime($moment);

        return new MigrationWriter($this->dir, static function () use ($timestamp): int {
            return $timestamp;
        });
    }

    private function write(string $name): void
    {
        file_put_contents($this->dir . '/' . $name, '<?php return function ($modx) {};');
    }
}

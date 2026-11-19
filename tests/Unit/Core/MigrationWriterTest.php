<?php

/**
 * Тесты выбора метки для новой миграции.
 *
 * Метка — текущее время в часовом поясе проекта: без сдвига «за последней в каталоге»
 * (одна метка из будущего тянула за собой все новые) и независимо от пояса процесса.
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

    /** Метка — текущее время, даже если в каталоге есть метка «из будущего»: сдвига нет. */
    public function testLabelIsCurrentTimeEvenWithFutureLabelInDirectory(): void
    {
        $this->write('20260901_1000__from_future.php');
        $writer = $this->writerAt('2026-08-06 19:08:00');

        $this->assertSame('20260806_1908__next.php', $writer->nextName('next'));
    }

    /** Пояс меток берётся из настройки, а не из пояса процесса (в тесте процесс в UTC). */
    public function testLabelUsesConfiguredTimezoneNotProcessOne(): void
    {
        $this->assertSame('UTC', date_default_timezone_get());
        $moment = (int)strtotime('2026-08-06 19:08:00 UTC');
        $clock = static function () use ($moment): int {
            return $moment;
        };

        $this->assertSame('20260806_2208__x.php', (new MigrationWriter($this->dir, $clock, 'Europe/Moscow'))->nextName('x'));
        $this->assertSame('20260806_2208__x.php', (new MigrationWriter($this->dir, $clock))->nextName('x'), 'умолчание — Москва');
        $this->assertSame('20260806_1908__x.php', (new MigrationWriter($this->dir, $clock, 'UTC'))->nextName('x'));
        $this->assertSame('20260806_1508__x.php', (new MigrationWriter($this->dir, $clock, 'America/New_York'))->nextName('x'));
    }

    public function testRejectsUnknownTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MigrationWriter($this->dir, null, 'Mars/Olympus');
    }

    public function testWriteCreatesFileAndReturnsPath(): void
    {
        $writer = $this->writerAt('2026-08-07 10:30:00');

        $path = $writer->write('do_something', '<?php // тело');

        $this->assertFileExists($path);
        $this->assertSame('20260807_1030__do_something.php', basename($path));
        $this->assertSame('<?php // тело', file_get_contents($path));
    }

    /** Разные слаги в одну минуту не конфликтуют и метка не уезжает в будущее. */
    public function testTwoWritesInTheSameMinuteKeepTheSameLabel(): void
    {
        $writer = $this->writerAt('2026-08-07 10:30:00');

        $first = $writer->write('first', 'один');
        $second = $writer->write('second', 'два');

        $this->assertSame('20260807_1030__first.php', basename($first));
        $this->assertSame('20260807_1030__second.php', basename($second));
        $this->assertSame('один', file_get_contents($first));
    }

    /** Тот же слаг в ту же минуту — понятная ошибка без совета переименовывать, файл цел. */
    public function testSameSlugInTheSameMinuteFailsWithoutOverwriting(): void
    {
        $writer = $this->writerAt('2026-08-07 10:30:00');
        $first = $writer->write('same', 'один');

        try {
            $writer->write('same', 'два');
            $this->fail('Ожидалась ошибка');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('уже существует', $e->getMessage());
            $this->assertStringNotContainsString('Переименуйте', $e->getMessage());
        }
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
        }, 'UTC');
    }

    private function write(string $name): void
    {
        file_put_contents($this->dir . '/' . $name, '<?php return function ($modx) {};');
    }
}

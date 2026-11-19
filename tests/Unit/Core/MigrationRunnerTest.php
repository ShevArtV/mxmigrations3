<?php

/**
 * Тесты ядра раннера миграций (#2608-12).
 *
 * Журнал, исполнитель и блокировка подменены заглушками, поэтому проверяется
 * именно логика: что считается применённым, что повторно не выполняется, как
 * ведёт себя dry-run, baseline, блокировка и расхождение checksum.
 */

namespace MxMigrations\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use MxMigrations\Core\MigrationFile;
use MxMigrations\Core\MigrationRecord;
use MxMigrations\Core\MigrationRunner;
use MxMigrations\Tests\Fixtures\FakeExecutor;
use MxMigrations\Tests\Fixtures\FakeJournal;
use MxMigrations\Tests\Fixtures\FakeLock;

class MigrationRunnerTest extends TestCase
{
    private string $dir;
    private FakeJournal $journal;
    private FakeExecutor $executor;
    private FakeLock $lock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sg-runner-' . uniqid('', true);
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

    public function testUpAppliesPendingInNameOrder(): void
    {
        $this->write('20260102_1000__b.php', '<?php return function ($modx) {};');
        $this->write('20260101_1000__a.php', '<?php return function ($modx) {};');

        $result = $this->runner()->up(false, false, 'tester');

        self::assertSame(['20260101_1000__a.php', '20260102_1000__b.php'], $this->executor->executed);
        self::assertCount(2, $result['executed']);
        self::assertNull($result['failed']);
        self::assertTrue($this->journal->tableEnsured);
        self::assertSame('tester', $this->journal->records['20260101_1000__a.php']->getAppliedBy());
    }

    public function testSecondRunAppliesNothing(): void
    {
        $this->write('20260101_1000__a.php', '<?php return function ($modx) {};');
        $runner = $this->runner();

        $runner->up();
        $this->executor->executed = [];
        $result = $runner->up();

        self::assertSame([], $this->executor->executed);
        self::assertSame([], $result['executed']);
    }

    public function testDryRunExecutesNothingAndWritesNothing(): void
    {
        $this->write('20260101_1000__a.php', '<?php return function ($modx) {};');

        $result = $this->runner()->up(true);

        self::assertTrue($result['dryRun']);
        self::assertSame(['20260101_1000__a.php'], array_column($result['executed'], 'name'));
        self::assertSame([], $this->executor->executed);
        self::assertSame([], $this->journal->records);
        self::assertSame(0, $this->lock->acquired, 'dry-run не должен занимать блокировку');
    }

    public function testBaselineMarksWithoutExecuting(): void
    {
        $this->write('add_legacy.php', '<?php return function ($modx) {};');

        $marked = $this->runner()->baseline('deploy');

        self::assertSame(['add_legacy.php'], $marked);
        self::assertSame([], $this->executor->executed);
        self::assertSame(MigrationRunner::STATUS_BASELINE, $this->journal->records['add_legacy.php']->getStatus());
        self::assertSame([], $this->runner()->plan()['pending'], 'после baseline миграция не ждёт применения');
    }

    public function testDriftBlocksUpUnlessIgnored(): void
    {
        $this->write('20260101_1000__a.php', '<?php return function ($modx) {};');
        $this->runner()->up();
        $this->write('20260101_1000__a.php', '<?php return function ($modx) { /* правка */ };');

        $plan = $this->runner()->plan();
        self::assertCount(1, $plan['drifted']);

        try {
            $this->runner()->up();
            self::fail('up обязан отказаться работать при расхождении checksum');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('изменены после применения', $e->getMessage());
        }

        $this->executor->executed = [];
        $result = $this->runner()->up(false, true);
        self::assertSame([], $this->executor->executed, 'изменённая миграция остаётся применённой, а не переигрывается');
        self::assertSame(['20260101_1000__a.php'], $result['accepted']);
        self::assertSame(
            [],
            $this->runner()->plan()['drifted'],
            '--ignore-checksum принимает новое содержимое как эталон, иначе флаг нужен был бы каждый раз'
        );
        self::assertSame(
            MigrationRunner::STATUS_APPLIED,
            $this->journal->records['20260101_1000__a.php']->getStatus(),
            'принятие правки не меняет статус миграции'
        );
    }

    public function testDryRunDoesNotAcceptDrift(): void
    {
        $this->write('20260101_1000__a.php', '<?php return function ($modx) {};');
        $this->runner()->up();
        $this->write('20260101_1000__a.php', '<?php return function ($modx) { /* правка */ };');

        $result = $this->runner()->up(true, true);

        self::assertSame(['20260101_1000__a.php'], $result['accepted'], 'dry-run показывает, что будет принято');
        self::assertCount(1, $this->runner()->plan()['drifted'], 'но журнал не трогает');
    }

    public function testFailedMigrationStopsRunAndStaysPending(): void
    {
        $this->write('20260101_1000__a.php', '<?php return function ($modx) {};');
        $this->write('20260102_1000__boom.php', '<?php return function ($modx) {};');
        $this->write('20260103_1000__c.php', '<?php return function ($modx) {};');
        $this->executor->failOn = '20260102_1000__boom.php';

        $result = $this->runner()->up();

        self::assertSame('20260102_1000__boom.php', $result['failed']['name']);
        self::assertSame(['20260103_1000__c.php'], $result['skipped']);
        self::assertSame(
            MigrationRunner::STATUS_FAILED,
            $this->journal->records['20260102_1000__boom.php']->getStatus()
        );

        $pending = array_map(static function (MigrationFile $file): string {
            return $file->getName();
        }, $this->runner()->plan()['pending']);
        self::assertSame(['20260102_1000__boom.php', '20260103_1000__c.php'], $pending);
    }

    public function testBusyLockAbortsRun(): void
    {
        $this->write('20260101_1000__a.php', '<?php return function ($modx) {};');
        $this->lock->free = false;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('другой процесс');

        $this->runner()->up();
    }

    public function testLockIsReleasedAfterRun(): void
    {
        $this->write('20260101_1000__a.php', '<?php return function ($modx) {};');

        $this->runner()->up();

        self::assertSame(1, $this->lock->acquired);
        self::assertSame(1, $this->lock->released);
    }

    public function testRecordWithoutFileIsReportedAsMissing(): void
    {
        $this->journal->records['ушедший.php'] = new MigrationRecord(
            'ушедший.php',
            'abc',
            MigrationRunner::STATUS_APPLIED,
            1000
        );

        $plan = $this->runner()->plan();

        self::assertCount(1, $plan['missing']);
        self::assertSame('ушедший.php', $plan['missing'][0]->getName());
    }

    /** По умолчанию метка раньше применённых не мешает: миграция применяется, в результате — предупреждение. */
    public function testOutOfOrderIsAllowedByDefaultWithWarning(): void
    {
        $this->write('20260102_1000__applied.php', '<?php return function ($modx) {};');
        $runner = $this->runner();
        $runner->up();

        $this->write('20260101_1000__latecomer.php', '<?php return function ($modx) {};');
        $this->executor->executed = [];

        $plan = $runner->plan();
        self::assertCount(1, $plan['outOfOrder']);
        self::assertSame('20260101_1000__latecomer.php', $plan['outOfOrder'][0]->getName());

        $result = $runner->up();

        self::assertSame(['20260101_1000__latecomer.php'], $this->executor->executed);
        self::assertNull($result['failed']);
        self::assertCount(1, $result['warnings']);
        self::assertStringContainsString('20260101_1000__latecomer.php', $result['warnings'][0]);
        self::assertSame([], $result['errors']);
    }

    /** Две параллельные ветки с метками вразнобой применяются без отказа. */
    public function testParallelBranchesWithShuffledLabelsApplyWithoutRefusal(): void
    {
        $this->write('20260105_1000__base.php', '<?php return function ($modx) {};');
        $runner = $this->runner();
        $runner->up();
        $this->write('20260103_0900__branch_a.php', '<?php return function ($modx) {};');
        $this->write('20260102_1500__branch_b.php', '<?php return function ($modx) {};');
        $this->executor->executed = [];

        $result = $runner->up();

        self::assertSame(['20260102_1500__branch_b.php', '20260103_0900__branch_a.php'], $this->executor->executed);
        self::assertNull($result['failed']);
    }

    /** Строгий режим из конфига возвращает отказ; в тексте нет совета переименовывать. */
    public function testStrictOrderRefusesMigrationThatJumpsBeforeAppliedOnes(): void
    {
        $this->write('20260102_1000__applied.php', '<?php return function ($modx) {};');
        $this->runner()->up();
        $this->write('20260101_1000__latecomer.php', '<?php return function ($modx) {};');
        $this->executor->executed = [];

        try {
            $this->runner(true)->up();
            self::fail('Ожидался отказ');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('20260101_1000__latecomer.php', $e->getMessage());
            self::assertStringContainsString('--allow-out-of-order', $e->getMessage());
            self::assertStringNotContainsString('ереименуйте', $e->getMessage());
        }
        self::assertSame([], $this->executor->executed);
    }

    public function testAllowOutOfOrderFlagOverridesStrictOrder(): void
    {
        $this->write('20260102_1000__applied.php', '<?php return function ($modx) {};');
        $this->runner()->up();
        $this->write('20260101_1000__latecomer.php', '<?php return function ($modx) {};');
        $this->executor->executed = [];

        $result = $this->runner(true)->up(false, false, '', true);

        self::assertSame(['20260101_1000__latecomer.php'], $this->executor->executed);
        self::assertCount(1, $result['warnings']);
    }

    /** Сухой прогон в строгом режиме показывает весь список и возвращает ту же ошибку, что и настоящий. */
    public function testDryRunInStrictOrderReportsErrorWithFullList(): void
    {
        $this->write('20260102_1000__applied.php', '<?php return function ($modx) {};');
        $this->runner()->up();
        $this->write('20260101_1000__latecomer.php', '<?php return function ($modx) {};');

        $result = $this->runner(true)->up(true);

        self::assertCount(1, $result['executed']);
        self::assertCount(1, $result['errors']);
        self::assertSame([], $result['warnings']);
    }

    public function testDryRunInAllowOrderOnlyWarns(): void
    {
        $this->write('20260102_1000__applied.php', '<?php return function ($modx) {};');
        $runner = $this->runner();
        $runner->up();
        $this->write('20260101_1000__latecomer.php', '<?php return function ($modx) {};');

        $result = $runner->up(true);

        self::assertCount(1, $result['executed']);
        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['warnings']);
    }

    /**
     * Baseline-файлы без метки границу не задают.
     *
     * Их имена начинаются с буквы, а буквы в ASCII больше цифр, поэтому в общей
     * сортировке они всегда последние. Если считать границу по ним, «раньше
     * применённых» окажется любая новая миграция — на dev так и вышло при первой
     * живой проверке.
     */
    public function testBaselineFilesWithoutLabelDoNotSetTheBoundary(): void
    {
        $this->write('fix_header_menu_structure.php', '<?php return function ($modx) {};');
        $this->write('20260101_1000__applied.php', '<?php return function ($modx) {};');
        $runner = $this->runner();
        $runner->up();

        $this->write('20260102_1000__fresh.php', '<?php return function ($modx) {};');

        self::assertSame([], $runner->plan()['outOfOrder']);
    }

    /**
     * Упавшая миграция остаётся в pending, но пришельцем из прошлого не считается:
     * запись в журнале у неё уже есть, своё место в очереди она заняла раньше.
     */
    public function testFailedMigrationIsNotReportedAsOutOfOrder(): void
    {
        $this->write('20260101_1000__failing.php', '<?php return function ($modx) {};');
        $this->write('20260102_1000__ok.php', '<?php return function ($modx) {};');
        $this->executor->failOn = '20260101_1000__failing.php';
        $runner = $this->runner();
        $runner->up();

        $this->executor->failOn = null;
        $plan = $runner->plan();

        self::assertSame([], $plan['outOfOrder']);
    }

    private function runner(bool $strictOrder = false): MigrationRunner
    {
        return new MigrationRunner($this->dir, $this->journal, $this->executor, $this->lock, null, $strictOrder);
    }

    /** К содержимому добавляется имя файла: одинаковые заглушки раннер принял бы за переименование. */
    private function write(string $name, string $contents): void
    {
        file_put_contents($this->dir . '/' . $name, $contents . "\n// " . $name . "\n");
    }
}

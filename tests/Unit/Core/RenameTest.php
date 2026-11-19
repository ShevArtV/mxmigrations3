<?php

/** Призраки переименований: распознавание по checksum и по `@renamed-from`, чистка журнала. */

namespace MxMigrations\Tests\Unit\Core;

use MxMigrations\Core\MigrationRecord;

final class RenameTest extends RunnerTestCase
{
    private const BODY = "<?php\nreturn function (\$modx) { /* тело */ };\n";

    /** Файл переименован, старого на диске нет: не выполняется повторно, не падает. */
    public function testRenamedAppliedMigrationIsNotExecutedAgain(): void
    {
        $this->applyOld('20260101_1000__old.php');
        rename($this->dir . '/20260101_1000__old.php', $this->dir . '/20260101_1000__new.php');
        $this->executor->executed = [];

        $result = $this->runner()->up();

        self::assertSame([], $this->executor->executed);
        self::assertNull($result['failed']);
        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['renamed']);
        $plan = $this->runner()->plan();
        self::assertSame([], $plan['pending']);
        self::assertSame([], $plan['missing'], 'призрак учтён как переименование, а не как пропажа');
        self::assertCount(1, $plan['renamed']);
    }

    /** Выкладка `protect`: старый файл остался рядом с новым — всё равно переименование. */
    public function testRenameIsRecognizedWhenOldFileRemainsOnDisk(): void
    {
        $this->applyOld('20260101_1000__old.php');
        copy($this->dir . '/20260101_1000__old.php', $this->dir . '/20260101_1000__new.php');
        $this->executor->executed = [];

        $result = $this->runner()->up();

        self::assertSame([], $this->executor->executed);
        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['renamed']);
    }

    /** Одинаковый checksum у нескольких неприменённых файлов — дубликаты: отказ, ни один не выполнен. */
    public function testUnappliedDuplicatesRefuseTheRun(): void
    {
        file_put_contents($this->dir . '/20260101_1000__old.php', self::BODY);
        file_put_contents($this->dir . '/20260101_1000__new.php', self::BODY);
        file_put_contents($this->dir . '/20260102_1000__other.php', "<?php\n// другая\n");

        $dry = $this->runner()->up(true);
        self::assertCount(1, $dry['errors']);
        self::assertStringContainsString('20260101_1000__old.php', $dry['errors'][0]);
        self::assertStringContainsString('20260101_1000__new.php', $dry['errors'][0]);

        try {
            $this->runner()->up();
            self::fail('Ожидался отказ');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('одинаковое содержимое', $e->getMessage());
        }
        self::assertSame([], $this->executor->executed);
    }

    /** Разные миграции с разным содержимым не путаются. */
    public function testDifferentContentIsNotMistakenForRename(): void
    {
        $this->applyOld('20260101_1000__old.php');
        file_put_contents($this->dir . '/20260102_1000__fresh.php', "<?php\n// другое\n");
        $this->executor->executed = [];

        $this->runner()->up();

        self::assertSame(['20260102_1000__fresh.php'], $this->executor->executed);
    }

    /** Пустые файлы под распознавание не подпадают: это не переименование. */
    public function testEmptyFilesAreNeverRenames(): void
    {
        file_put_contents($this->dir . '/20260101_1000__a.php', '');
        $this->runner()->up();
        file_put_contents($this->dir . '/20260102_1000__b.php', '');
        $this->executor->executed = [];

        $this->runner()->up();

        self::assertSame(['20260102_1000__b.php'], $this->executor->executed);
    }

    /** Метка `@renamed-from` работает и когда содержимое при переименовании менялось. */
    public function testRenamedFromMarkerSurvivesContentChange(): void
    {
        $this->applyOld('20260101_1000__old.php');
        unlink($this->dir . '/20260101_1000__old.php');
        file_put_contents(
            $this->dir . '/20260101_1000__new.php',
            "<?php\n// @renamed-from 20260101_1000__old.php\n// правка\nreturn function (\$modx) {};\n"
        );
        $this->executor->executed = [];

        $result = $this->runner()->up();

        self::assertSame([], $this->executor->executed);
        self::assertCount(1, $result['renamed']);
    }

    /** Без метки и с изменённым содержимым переименование не распознаётся — ограничение задокументировано. */
    public function testRenameWithContentChangeWithoutMarkerIsNotRecognized(): void
    {
        $this->applyOld('20260101_1000__old.php');
        unlink($this->dir . '/20260101_1000__old.php');
        file_put_contents($this->dir . '/20260101_1000__new.php', "<?php\n// иное\n");
        $this->executor->executed = [];

        $this->runner()->up();

        self::assertSame(['20260101_1000__new.php'], $this->executor->executed);
    }

    public function testCleanDryRunShowsGhostAndWritesNothing(): void
    {
        $this->applyOld('20260101_1000__old.php');
        rename($this->dir . '/20260101_1000__old.php', $this->dir . '/20260101_1000__new.php');
        $before = $this->journal->records;
        $acquired = $this->lock->acquired;

        $result = $this->runner()->cleanGhosts(false);

        self::assertFalse($result['apply']);
        self::assertSame([['from' => '20260101_1000__old.php', 'to' => '20260101_1000__new.php', 'fileRemains' => false]], $result['renamed']);
        self::assertSame($before, $this->journal->records);
        self::assertSame($acquired, $this->lock->acquired, 'dry-run не берёт блокировку');
    }

    public function testCleanApplyRenamesRecordKeepingStatus(): void
    {
        $this->applyOld('20260101_1000__old.php');
        $old = $this->journal->records['20260101_1000__old.php'];
        rename($this->dir . '/20260101_1000__old.php', $this->dir . '/20260101_1000__new.php');

        $released = $this->lock->released;
        $this->runner()->cleanGhosts(true, 'tester');

        self::assertArrayNotHasKey('20260101_1000__old.php', $this->journal->records);
        $record = $this->journal->records['20260101_1000__new.php'];
        self::assertSame($old->getChecksum(), $record->getChecksum());
        self::assertSame($old->getAppliedAt(), $record->getAppliedAt());
        self::assertSame('applied', $record->getStatus());
        self::assertSame($released + 1, $this->lock->released);
        $plan = $this->runner()->plan();
        self::assertSame([], $plan['renamed']);
        self::assertSame([], $plan['pending']);
        self::assertSame([], $plan['missing']);
        self::assertCount(1, $plan['applied']);
    }

    /**
     * Старый файл остался на диске: чистка переименовывает запись, а старый файл
     * показывает как лишний — не как переименование и не как новую миграцию.
     */
    public function testCleanWithLeftoverOldFileReportsItAsExtraAfterwards(): void
    {
        $this->applyOld('20260101_1000__old.php');
        copy($this->dir . '/20260101_1000__old.php', $this->dir . '/20260101_1000__new.php');

        $dry = $this->runner()->cleanGhosts(false);
        self::assertTrue($dry['renamed'][0]['fileRemains']);
        self::assertSame(['20260101_1000__old.php'], $dry['extra']);

        $this->runner()->cleanGhosts(true);
        $this->executor->executed = [];

        $plan = $this->runner()->plan();
        self::assertCount(1, $plan['renamed']);
        self::assertTrue($plan['renamed'][0]['extra']);
        self::assertSame('20260101_1000__old.php', $plan['renamed'][0]['file']->getName());
        self::assertSame([], $plan['pending']);

        $result = $this->runner()->up();
        self::assertSame([], $this->executor->executed);
        self::assertStringContainsString('лишний файл', $result['renamed'][0]);

        $again = $this->runner()->cleanGhosts(true);
        self::assertSame([], $again['renamed']);
        self::assertSame(['20260101_1000__old.php'], $again['extra']);
    }

    /** Запись без файла и без пары остаётся в `unmatched` и не трогается. */
    public function testCleanLeavesUnmatchedGhostsAlone(): void
    {
        $this->journal->records['20260101_1000__gone.php'] = new MigrationRecord('20260101_1000__gone.php', str_repeat('a', 64), 'applied', 1);

        $result = $this->runner()->cleanGhosts(true);

        self::assertSame(['20260101_1000__gone.php'], $result['unmatched']);
        self::assertArrayHasKey('20260101_1000__gone.php', $this->journal->records);
    }

    public function testCleanWithNothingToDo(): void
    {
        $result = $this->runner()->cleanGhosts(true);

        self::assertSame([], $result['renamed']);
        self::assertSame([], $result['extra']);
        self::assertSame([], $result['unmatched']);
    }

    private function applyOld(string $name): void
    {
        file_put_contents($this->dir . '/' . $name, self::BODY);
        $this->runner()->up();
    }
}

<?php

/** `@depends`: порядок, ошибки, цикл. */

namespace MxMigrations\Tests\Unit\Core;

final class DependsTest extends RunnerTestCase
{
    /** Без зависимостей порядок — по имени, как раньше. */
    public function testWithoutDependsOrderIsByName(): void
    {
        $this->write('20260102_1000__b.php');
        $this->write('20260101_1000__a.php');

        $this->runner()->up();

        self::assertSame(['20260101_1000__a.php', '20260102_1000__b.php'], $this->executor->executed);
    }

    /** Зависимость из текущего прогона применяется раньше, даже если имя больше. */
    public function testDependencyInRunGoesFirst(): void
    {
        $this->write('20260101_1000__a.php', ' * @depends 20260105_1000__z.php');
        $this->write('20260102_1000__b.php');
        $this->write('20260105_1000__z.php');

        $plan = $this->runner()->plan();

        self::assertSame([], $plan['errors']);
        self::assertSame(
            ['20260102_1000__b.php', '20260105_1000__z.php', '20260101_1000__a.php'],
            array_map(static function ($f) {
                return $f->getName();
            }, $plan['pending'])
        );
    }

    public function testDependsAcceptsNameWithoutExtensionAndDoubleSlashComment(): void
    {
        $this->write('20260101_1000__a.php');
        $this->write('20260102_1000__b.php');
        file_put_contents(
            $this->dir . '/20260101_1000__a.php',
            "<?php\n// @depends 20260102_1000__b\nreturn function (\$modx) {};\n"
        );

        $this->runner()->up();

        self::assertSame(['20260102_1000__b.php', '20260101_1000__a.php'], $this->executor->executed);
    }

    public function testAlreadyAppliedDependencyIsFine(): void
    {
        $this->write('20260101_1000__base.php');
        $this->runner()->up();
        $this->write('20260102_1000__next.php', ' * @depends 20260101_1000__base.php');
        $this->executor->executed = [];

        $result = $this->runner()->up();

        self::assertSame(['20260102_1000__next.php'], $this->executor->executed);
        self::assertNull($result['failed']);
    }

    /** Зависимости нет вовсе: отказ, в ошибке названы миграция и зависимость, ничего не выполнено. */
    public function testMissingDependencyRefusesRunWithNamedError(): void
    {
        $this->write('20260102_1000__next.php', ' * @depends 20260101_1000__ghost.php');
        $this->write('20260103_1000__other.php');

        try {
            $this->runner()->up();
            self::fail('Ожидался отказ');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('20260102_1000__next.php', $e->getMessage());
            self::assertStringContainsString('20260101_1000__ghost.php', $e->getMessage());
        }
        self::assertSame([], $this->executor->executed);
    }

    /** Сухой прогон: весь список и ошибка, исключения нет, ничего не записано. */
    public function testDryRunReportsDependencyErrorWithFullList(): void
    {
        $this->write('20260102_1000__next.php', ' * @depends 20260101_1000__ghost.php');
        $this->write('20260103_1000__other.php');

        $result = $this->runner()->up(true);

        self::assertCount(2, $result['executed']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('20260101_1000__ghost.php', $result['errors'][0]);
        self::assertSame([], $this->journal->records);
    }

    public function testCycleIsAnError(): void
    {
        $this->write('20260101_1000__a.php', ' * @depends 20260102_1000__b.php');
        $this->write('20260102_1000__b.php', ' * @depends 20260101_1000__a.php');
        $this->write('20260103_1000__c.php');

        $plan = $this->runner()->plan();
        self::assertCount(1, $plan['errors']);
        self::assertStringContainsString('Циклическая', $plan['errors'][0]);
        self::assertStringContainsString('20260101_1000__a.php -> 20260102_1000__b.php -> 20260101_1000__a.php', $plan['errors'][0]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Циклическая');
        $this->runner()->up();
    }

    public function testSelfDependencyIsAnError(): void
    {
        $this->write('20260101_1000__a.php', ' * @depends 20260101_1000__a.php');

        $plan = $this->runner()->plan();

        self::assertCount(1, $plan['errors']);
        self::assertStringContainsString('самой себя', $plan['errors'][0]);
    }

    /** Зависимость от упавшей миграции: она в очереди, а зависимая после сбоя пропущена. */
    public function testDependentIsSkippedWhenDependencyFails(): void
    {
        $this->write('20260102_1000__dep.php');
        $this->write('20260101_1000__a.php', ' * @depends 20260102_1000__dep.php');
        $this->executor->failOn = '20260102_1000__dep.php';

        $result = $this->runner()->up();

        self::assertSame('20260102_1000__dep.php', $result['failed']['name']);
        self::assertSame(['20260101_1000__a.php'], $result['skipped']);
    }

    /** `status --strict` опирается на plan()['errors'] — они есть в любом режиме порядка. */
    public function testDependencyErrorsAreInPlanInBothOrderModes(): void
    {
        $this->write('20260102_1000__next.php', ' * @depends 20260101_1000__ghost.php');

        self::assertCount(1, $this->runner(false)->plan()['errors']);
        self::assertCount(1, $this->runner(true)->plan()['errors']);
    }
}

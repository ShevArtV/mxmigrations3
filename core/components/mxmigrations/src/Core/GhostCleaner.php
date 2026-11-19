<?php

/**
 * Чистка журнала от призраков переименований.
 *
 * Для каждого признанного переименования запись журнала получает новое имя файла
 * (статус, время и checksum сохраняются). Файлы каталога не удаляются: лишний старый
 * файл только перечисляется — его убирает выкладка сайта. Без `$apply` ничего не
 * пишется и блокировка не берётся.
 */

namespace MxMigrations\Core;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class GhostCleaner
{
    private string $dir;
    private JournalInterface $journal;
    private LockInterface $lock;
    /** @var callable(): array<string, mixed> */
    private $planner;

    /** @param callable(): array<string, mixed> $planner источник актуального плана раннера */
    public function __construct(string $dir, JournalInterface $journal, LockInterface $lock, callable $planner)
    {
        $this->dir = $dir;
        $this->journal = $journal;
        $this->lock = $lock;
        $this->planner = $planner;
    }

    /**
     * @return array{
     *     apply: bool,
     *     renamed: array<int, array{from: string, to: string, fileRemains: bool}>,
     *     extra: array<int, string>,
     *     unmatched: array<int, string>
     * }
     */
    public function clean(bool $apply = false, string $appliedBy = ''): array
    {
        $result = ['apply' => $apply, 'renamed' => [], 'extra' => [], 'unmatched' => []];
        $plan = ($this->planner)();

        if ($apply && !$this->lock->acquire()) {
            throw new \RuntimeException('Миграции уже выполняет другой процесс — чистка журнала отменена.');
        }

        try {
            if ($apply) {
                $plan = ($this->planner)();
            }
            foreach ($plan['renamed'] as $item) {
                if ($item['extra']) {
                    $result['extra'][] = $item['file']->getName();
                    continue;
                }
                $from = $item['record']->getName();
                $to = $item['file']->getName();
                $result['renamed'][] = [
                    'from' => $from,
                    'to' => $to,
                    'fileRemains' => is_file($this->dir . '/' . $from),
                ];
                if ($apply) {
                    $this->journal->rename($from, $to, RenameDetector::NOTE_PREFIX . $from . sprintf(
                        ' (journal:clean %s, %s)',
                        date('Y-m-d H:i'),
                        $appliedBy !== '' ? $appliedBy : 'неизвестно кем'
                    ));
                }
            }
            foreach ($plan['missing'] as $record) {
                $result['unmatched'][] = $record->getName();
            }
        } finally {
            if ($apply) {
                $this->lock->release();
            }
        }

        foreach ($result['renamed'] as $pair) {
            if ($pair['fileRemains']) {
                $result['extra'][] = $pair['from'];
            }
        }

        return $result;
    }
}

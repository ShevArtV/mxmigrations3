<?php

namespace MxMigrations\Tests\Fixtures;

use MxMigrations\Core\ExecutorInterface;
use MxMigrations\Core\JournalInterface;
use MxMigrations\Core\LockInterface;
use MxMigrations\Core\MigrationFile;
use MxMigrations\Core\MigrationRecord;

class FakeJournal implements JournalInterface
{
    public bool $tableEnsured = false;

    /** @var array<string, MigrationRecord> */
    public array $records = [];

    public function ensureTable(): void
    {
        $this->tableEnsured = true;
    }

    public function all(): array
    {
        return $this->records;
    }

    public function record(MigrationRecord $record): void
    {
        $this->records[$record->getName()] = $record;
    }

    public function rename(string $from, string $to, string $note = ''): void
    {
        if (!isset($this->records[$from]) || isset($this->records[$to])) {
            throw new \RuntimeException('rename: нет записи или имя занято');
        }
        $old = $this->records[$from];
        unset($this->records[$from]);
        $this->records[$to] = new MigrationRecord(
            $to,
            $old->getChecksum(),
            $old->getStatus(),
            $old->getAppliedAt(),
            $old->getAppliedBy(),
            $old->getDuration(),
            trim($old->getOutput() . "\n" . $note)
        );
    }
}

<?php

namespace MxMigrations\Tests\Fixtures;

use MxMigrations\Core\ExecutorInterface;
use MxMigrations\Core\JournalInterface;
use MxMigrations\Core\LockInterface;
use MxMigrations\Core\MigrationFile;
use MxMigrations\Core\MigrationRecord;

class FakeLock implements LockInterface
{
    public bool $free = true;
    public int $acquired = 0;
    public int $released = 0;

    public function acquire(): bool
    {
        if (!$this->free) {
            return false;
        }
        $this->acquired++;

        return true;
    }

    public function release(): void
    {
        $this->released++;
    }
}

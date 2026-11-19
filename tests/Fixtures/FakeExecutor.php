<?php

namespace MxMigrations\Tests\Fixtures;

use MxMigrations\Core\ExecutorInterface;
use MxMigrations\Core\JournalInterface;
use MxMigrations\Core\LockInterface;
use MxMigrations\Core\MigrationFile;
use MxMigrations\Core\MigrationRecord;

class FakeExecutor implements ExecutorInterface
{
    /** @var array<int, string> */
    public array $executed = [];

    public ?string $failOn = null;

    public function execute(MigrationFile $file): string
    {
        if ($this->failOn === $file->getName()) {
            throw new \RuntimeException('синтетический сбой');
        }

        $this->executed[] = $file->getName();

        return 'ок';
    }
}

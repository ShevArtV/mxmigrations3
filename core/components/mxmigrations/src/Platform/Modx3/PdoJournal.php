<?php

namespace MxMigrations\Platform\Modx3;

use MODX\Revolution\modX;
use MxMigrations\Core\JournalInterface;
use MxMigrations\Core\MigrationRecord;

final class PdoJournal implements JournalInterface
{
    private const MAX_OUTPUT_LENGTH = 65535;

    private modX $modx;
    private string $table;

    public function __construct(modX $modx, string $table)
    {
        if (!preg_match('~^[a-zA-Z][a-zA-Z0-9_]*$~', $table)) {
            throw new \InvalidArgumentException('Недопустимое имя таблицы журнала: ' . $table);
        }
        $this->modx = $modx;
        $this->table = (string)$modx->getOption('table_prefix', null, 'modx_') . $table;
    }

    public function ensureTable(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->quotedTable() . ' ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`name` VARCHAR(191) NOT NULL,'
            . '`checksum` VARCHAR(64) NOT NULL DEFAULT \'\','
            . '`applied_at` INT NOT NULL DEFAULT 0,'
            . '`applied_by` VARCHAR(100) NOT NULL DEFAULT \'\','
            . '`duration` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`status` VARCHAR(16) NOT NULL DEFAULT \'applied\','
            . '`output` MEDIUMTEXT NULL,'
            . 'PRIMARY KEY (`id`), UNIQUE KEY `name` (`name`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
        if ($this->modx->exec($sql) === false) {
            throw new \RuntimeException('Не удалось создать таблицу журнала ' . $this->table . '.');
        }
    }

    public function all(): array
    {
        $statement = $this->modx->query(
            'SELECT `name`, `checksum`, `applied_at`, `applied_by`, `duration`, `status`, `output`'
            . ' FROM ' . $this->quotedTable() . ' ORDER BY `name`'
        );
        if ($statement === false) {
            throw new \RuntimeException('Не удалось прочитать журнал миграций ' . $this->table . '.');
        }
        $records = [];
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $record = MigrationRecord::fromArray($row);
            $records[$record->getName()] = $record;
        }

        return $records;
    }

    public function record(MigrationRecord $record): void
    {
        $sql = 'INSERT INTO ' . $this->quotedTable()
            . ' (`name`,`checksum`,`applied_at`,`applied_by`,`duration`,`status`,`output`)'
            . ' VALUES (:name,:checksum,:applied_at,:applied_by,:duration,:status,:output)'
            . ' ON DUPLICATE KEY UPDATE `checksum`=VALUES(`checksum`),'
            . '`applied_at`=VALUES(`applied_at`),`applied_by`=VALUES(`applied_by`),'
            . '`duration`=VALUES(`duration`),`status`=VALUES(`status`),`output`=VALUES(`output`)';
        $statement = $this->modx->prepare($sql);
        $data = $record->toArray();
        $data['output'] = mb_substr((string)$data['output'], 0, self::MAX_OUTPUT_LENGTH);
        if (!$statement || !$statement->execute($data)) {
            throw new \RuntimeException('Не удалось записать в журнал миграцию ' . $record->getName() . '.');
        }
    }

    public function rename(string $from, string $to, string $note = ''): void
    {
        $statement = $this->modx->prepare(
            'UPDATE ' . $this->quotedTable() . ' SET `name`=:to,'
            . ' `output`=TRIM(CONCAT(IFNULL(`output`, \'\'), CHAR(10), :note))'
            . ' WHERE `name`=:from'
        );
        if (!$statement || !$statement->execute(['to' => $to, 'from' => $from, 'note' => $note]) || $statement->rowCount() !== 1) {
            throw new \RuntimeException('Не удалось переименовать запись журнала ' . $from . ' в ' . $to . '.');
        }
    }

    private function quotedTable(): string
    {
        return '`' . $this->table . '`';
    }
}

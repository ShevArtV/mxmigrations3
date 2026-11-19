<?php

/**
 * Контракт журнала миграций.
 *
 * Отделён от реализации, чтобы ядро раннера тестировалось без MODX и без БД:
 * `composer test` гоняется на голом PHP.
 */

namespace MxMigrations\Core;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
interface JournalInterface
{
    /**
     * Создаёт таблицу журнала, если её ещё нет.
     *
     * Вызывается перед любой операцией: журнал не может быть заведён миграцией,
     * иначе получится курица и яйцо.
     */
    public function ensureTable(): void;

    /**
     * @return array<string, MigrationRecord> Ключ — имя миграции.
     */
    public function all(): array;

    public function record(MigrationRecord $record): void;

    /**
     * Переименовать запись (призрак переименования), сохранив статус и время применения.
     *
     * @throws \RuntimeException если записи нет или новое имя уже занято
     */
    public function rename(string $from, string $to, string $note = ''): void;
}

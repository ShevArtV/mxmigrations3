<?php

/**
 * Блокировка прогона миграций через MySQL GET_LOCK.
 *
 * Почему не lock-файл: блокировать надо доступ к БД, а не к каталогу. Раннер могут
 * запустить из разных мест (деплой-скрипт, крон, руками по SSH) и даже с разных
 * хостов, смотрящих в одну базу, — файловый лок такие запуски не увидит.
 *
 * Блокировка живёт в рамках соединения: если процесс убили `kill -9`, MySQL снимет
 * её сам при разрыве соединения — зависшего лока не остаётся.
 */

namespace MxMigrations\Platform\Modx3;

use MODX\Revolution\modX;
use MxMigrations\Core\LockInterface;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class MysqlLock implements LockInterface
{
    /** Имя лока в MySQL ограничено 64 символами. */
    private const MAX_NAME_LENGTH = 64;

    private modX $modx;
    private string $name;
    private bool $held = false;

    public function __construct(modX $modx, string $key)
    {
        $this->modx = $modx;
        /* Лок в MySQL глобален для всего сервера, а не для базы: имя базы в ключе
           не даёт двум сайтам на одном сервере блокировать друг друга. */
        $database = (string)$this->modx->getOption('dbname', null, '');
        $this->name = substr($database . ':' . $key, 0, self::MAX_NAME_LENGTH);
    }

    public function acquire(): bool
    {
        $statement = $this->modx->prepare('SELECT GET_LOCK(:name, 0)');
        if (!$statement || !$statement->execute([':name' => $this->name])) {
            throw new \RuntimeException('Не удалось запросить блокировку миграций.');
        }

        $this->held = (int)$statement->fetchColumn() === 1;

        return $this->held;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        $statement = $this->modx->prepare('SELECT RELEASE_LOCK(:name)');
        if ($statement) {
            $statement->execute([':name' => $this->name]);
        }
        $this->held = false;
    }
}

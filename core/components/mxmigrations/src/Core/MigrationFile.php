<?php

/**
 * Файл миграции: имя, тип и контрольная сумма.
 *
 * Ключ идемпотентности — имя файла, оно же попадает в журнал. Порядок применения
 * задаётся именем (конвенция `YYYYMMDD_HHMM__slug.php`). Переименованную применённую
 * миграцию раннер узнаёт по checksum содержимого или по метке `@renamed-from` в файле
 * (см. `RenameDetector`) и повторно не выполняет.
 *
 * Метки в комментариях файла: `@depends <имя миграции>` — миграция выполнится только
 * после указанной; `@renamed-from <старое имя>` — файл есть та же миграция под новым
 * именем (нужна, если при переименовании менялось и содержимое).
 */

namespace MxMigrations\Core;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class MigrationFile
{
    public const TYPE_PHP = 'php';

    private string $path;
    private string $name;
    private string $type;

    /** Содержимое читается лениво и один раз: нужно и для checksum, и для выполнения. */
    private ?string $contents = null;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->name = basename($path);
        $this->type = self::TYPE_PHP;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Содержимое файла с нормализованными переводами строк.
     *
     * CRLF и BOM срезаются до расчёта checksum: файлы правятся и из Windows, и из
     * Linux, и одна лишь смена окончаний строк не должна выглядеть как «миграцию
     * поменяли задним числом».
     */
    public function getContents(): string
    {
        if ($this->contents === null) {
            $raw = @file_get_contents($this->path);
            if ($raw === false) {
                throw new \RuntimeException('Не удалось прочитать файл миграции: ' . $this->path);
            }
            $raw = preg_replace('~^\xEF\xBB\xBF~', '', $raw) ?? $raw;
            $this->contents = str_replace(["\r\n", "\r"], "\n", $raw);
        }

        return $this->contents;
    }

    public function getChecksum(): string
    {
        return hash('sha256', $this->getContents());
    }

    /**
     * Метка времени из имени (`20260806_2100`) или null у файлов вне конвенции.
     *
     * Вне конвенции — три baseline-файла, написанные до раннера. Их имена начинаются с
     * буквы, а буквы в ASCII больше цифр, поэтому в общей сортировке они всегда стоят
     * последними. Для порядка применения это безразлично (они помечены применёнными и
     * не выполняются), но сравнивать с ними метки нельзя: любая новая миграция окажется
     * «раньше» такого файла.
     */
    public function getLabel(): ?string
    {
        return preg_match('~^(\d{8}_\d{4})__~', $this->name, $match) ? $match[1] : null;
    }

    /**
     * Имена миграций из `@depends` (с расширением `.php`, без повторов).
     *
     * @return array<int, string>
     */
    public function getDepends(): array
    {
        return $this->annotation('depends');
    }

    /** Старое имя из `@renamed-from` или null. */
    public function getRenamedFrom(): ?string
    {
        return $this->annotation('renamed-from')[0] ?? null;
    }

    /** @return array<int, string> */
    private function annotation(string $tag): array
    {
        $found = [];
        if (preg_match_all('~^[ \t]*(?://|\#|/\*+|\*)[ \t]*@' . $tag . '[ \t]+([A-Za-z0-9_.\-]+)~m', $this->getContents(), $m)) {
            foreach ($m[1] as $name) {
                if (substr($name, -4) !== '.php') {
                    $name .= '.php';
                }
                $found[$name] = $name;
            }
        }

        return array_values($found);
    }

    /**
     * Миграция сама поднимает MODX (`define('MODX_API_MODE')` + bootstrap ядра).
     *
     * Так написаны файлы, созданные до появления раннера, и так же — миграции снятия
     * пакетов. Включить такой файл в уже работающий процесс нельзя: повторный bootstrap
     * конфликтует с уже загруженным ядром, поэтому раннер запускает файл отдельным
     * процессом PHP.
     */
    public function isLegacyBootstrap(): bool
    {
        return (bool)preg_match('~\bMODX_API_MODE\b~', $this->getContents());
    }
}

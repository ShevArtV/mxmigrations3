<?php

/**
 * Распознавание переименованных миграций («призраков» в журнале).
 *
 * Ключ журнала — имя файла, поэтому переименованная в git применённая миграция для
 * раннера выглядит как новая: запись остаётся под старым именем, а файл под новым в
 * журнале не значится. Детектор находит такие пары по содержимому (checksum) или по
 * метке `@renamed-from <старое имя>` в файле. Файл, признанный переименованием, не
 * выполняется и не считается новым.
 *
 * Распознавание не зависит от того, остался ли старый файл на диске: при выкладке
 * rsync с защитой каталога (`protect`) старый файл лежит рядом с новым.
 *
 * Компромиссы:
 *  - переименование с одновременной правкой содержимого по checksum не узнать — для
 *    него нужна метка `@renamed-from`;
 *  - настоящая новая миграция, содержимое которой совпало с уже применённой, будет
 *    принята за переименование: отличите её любым комментарием. Пустые файлы в
 *    сравнении не участвуют;
 *  - несколько неприменённых файлов с одинаковым содержимым — это не две миграции, а
 *    дубликаты (например, файл переименовали до выкладки, и на сервере лежат оба
 *    имени): раннер отказывает с ошибкой, не выполняя ни одного.
 */

namespace MxMigrations\Core;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class RenameDetector
{
    /** Строка в output записи, которую пишет `journal:clean` при переименовании записи. */
    public const NOTE_PREFIX = 'renamed-from: ';

    /**
     * @param array<int, MigrationFile>        $files   все файлы каталога
     * @param array<string, MigrationRecord>   $records весь журнал
     *
     * @return array{
     *     renamed: array<int, array{file: MigrationFile, record: MigrationRecord, extra: bool}>,
     *     duplicates: array<int, array<int, MigrationFile>>
     * }
     */
    public function detect(array $files, array $records): array
    {
        $fileNames = [];
        foreach ($files as $file) {
            $fileNames[$file->getName()] = true;
        }

        $candidates = [];
        foreach ($files as $file) {
            if (!isset($records[$file->getName()])) {
                $candidates[] = $file;
            }
        }

        $renamed = [];
        $claimed = [];
        $rest = [];

        /* 1. Явная метка — работает и при изменённом содержимом. */
        foreach ($candidates as $file) {
            $from = $file->getRenamedFrom();
            $record = $from !== null ? ($records[$from] ?? null) : null;
            if ($record && $this->isDone($record) && !isset($claimed[$record->getName()])) {
                $claimed[$record->getName()] = true;
                $renamed[] = ['file' => $file, 'record' => $record, 'extra' => $this->isExtra($file, $record)];
                continue;
            }
            $rest[] = $file;
        }

        /* 2. Одинаковое содержимое у нескольких неприменённых файлов — дубликаты. */
        $byChecksum = [];
        foreach ($rest as $file) {
            if (trim($file->getContents()) === '') {
                continue;
            }
            $byChecksum[$file->getChecksum()][] = $file;
        }
        $duplicates = [];
        $ambiguous = [];
        foreach ($byChecksum as $group) {
            if (count($group) > 1) {
                $duplicates[] = $group;
                foreach ($group as $file) {
                    $ambiguous[$file->getName()] = true;
                }
            }
        }

        /* 3. Одиночный файл с checksum применённой записи под другим именем. */
        foreach ($rest as $file) {
            if (isset($ambiguous[$file->getName()]) || trim($file->getContents()) === '') {
                continue;
            }
            $record = $this->matchByChecksum($file, $records, $fileNames, $claimed);
            if ($record) {
                $claimed[$record->getName()] = true;
                $renamed[] = ['file' => $file, 'record' => $record, 'extra' => $this->isExtra($file, $record)];
            }
        }

        return ['renamed' => $renamed, 'duplicates' => $duplicates];
    }

    /**
     * Запись с тем же checksum: сначала призрак (файла нет), затем запись, чей старый
     * файл ещё лежит на диске.
     *
     * @param array<string, MigrationRecord> $records
     * @param array<string, bool>            $fileNames
     * @param array<string, bool>            $claimed
     */
    private function matchByChecksum(MigrationFile $file, array $records, array $fileNames, array $claimed): ?MigrationRecord
    {
        $match = null;
        foreach ($records as $record) {
            if (
                !$this->isDone($record)
                || $record->getChecksum() !== $file->getChecksum()
                || isset($claimed[$record->getName()])
            ) {
                continue;
            }
            if (!isset($fileNames[$record->getName()])) {
                return $record;
            }
            $match = $match ?: $record;
        }

        return $match;
    }

    private function isDone(MigrationRecord $record): bool
    {
        return in_array($record->getStatus(), [MigrationRunner::STATUS_APPLIED, MigrationRunner::STATUS_BASELINE], true)
            && $record->getChecksum() !== '';
    }

    /** Файл — старое имя, записанное при чистке журнала: лишний, а не переименование. */
    private function isExtra(MigrationFile $file, MigrationRecord $record): bool
    {
        return (bool)preg_match(
            '~^' . preg_quote(self::NOTE_PREFIX, '~') . preg_quote($file->getName(), '~') . '\b~m',
            $record->getOutput()
        );
    }
}

<?php

/**
 * Тексты и условия отказа прогона по плану миграций: что блокирует `up`, о чём
 * предупреждать, как показать переименования.
 */

namespace MxMigrations\Core;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class PlanReport
{
    /**
     * Причины, по которым прогон невозможен: ошибки зависимостей и дубликаты файлов, а в
     * строгом порядке (`order = strict`) — ещё и метка раньше применённых.
     *
     * @param array<string, mixed> $plan
     *
     * @return array<int, string>
     */
    public static function blockers(array $plan, bool $strictOrder, bool $allowOutOfOrder): array
    {
        $errors = $plan['errors'];
        if ($strictOrder && !$allowOutOfOrder && $plan['outOfOrder']) {
            $errors[] = 'Режим order = strict не допускает миграции с меткой раньше уже применённых: '
                . self::names($plan['outOfOrder']) . '. '
                . 'Задайте порядок явно через @depends, включите order = allow в конфиге '
                . 'или запустите с --allow-out-of-order.';
        }

        return $errors;
    }

    /**
     * Предупреждения о порядке: миграция применяется, но на сервере, поднятом с нуля,
     * она встанет по метке, то есть в другое место очереди.
     *
     * @param array<string, mixed> $plan
     *
     * @return array<int, string>
     */
    public static function orderWarnings(array $plan, bool $strictOrder, bool $allowOutOfOrder): array
    {
        if (!$plan['outOfOrder'] || ($strictOrder && !$allowOutOfOrder)) {
            return [];
        }

        return ['Миграции с меткой раньше уже применённых: ' . self::names($plan['outOfOrder'])
            . '. Они применятся сейчас, а на сервере, поднятом с нуля, выполнятся в порядке меток; '
            . 'если порядок важен, укажите @depends.'];
    }

    /** @param array<int, MigrationFile> $files */
    private static function names(array $files): string
    {
        return implode(', ', array_map(static function (MigrationFile $file): string {
            return $file->getName();
        }, $files));
    }

    /**
     * @param array<int, array{file: MigrationFile, record: MigrationRecord, extra: bool}> $renamed
     *
     * @return array<int, string>
     */
    public static function renamedLines(array $renamed): array
    {
        return array_map(static function (array $item): string {
            return $item['extra']
                ? $item['file']->getName() . ' — лишний файл: старое имя уже заменено на ' . $item['record']->getName()
                : $item['file']->getName() . ' — переименование ' . $item['record']->getName() . ', не выполняется';
        }, $renamed);
    }
}

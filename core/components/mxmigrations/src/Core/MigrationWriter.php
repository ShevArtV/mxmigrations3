<?php

/**
 * Создание файлов миграций: имя и запись на диск.
 *
 * Единственное место, где решается, как называется новая миграция. До этой задачи
 * метку выбирали руками (и получили восемь файлов `20260806_1236`), а генератор
 * удаления пакетов брал голое `date('Ymd_Hi')` — то есть ровно то же самое, только
 * автоматически.
 *
 * Правило метки: текущее время в часовом поясе проекта (`label_timezone`, по
 * умолчанию `Europe/Moscow` — так ставились метки первых проектов). Консольный PHP
 * обычно работает в UTC, поэтому пояс задаётся явно, а не берётся из `date()`.
 * Сдвигать метку «за последней в каталоге» не нужно: порядок применения больше не
 * ломается из-за метки раньше последней (см. `order` в конфиге проекта), а метка из
 * будущего тянула бы за собой все следующие.
 *
 * Формат метки не менять на `YYYYMMDD_HHMMSS`: `_` в ASCII (95) больше цифры (48),
 * поэтому `20260806_215012__b.php` сортируется РАНЬШЕ `20260806_2150__a.php`, и
 * смешанный набор поедет не в том порядке.
 */

namespace MxMigrations\Core;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class MigrationWriter
{
    /** Часовой пояс меток по умолчанию: так ставились метки существующих проектов. */
    public const DEFAULT_TIMEZONE = 'Europe/Moscow';

    /** Метка времени в имени файла: `YYYYMMDD_HHMM`. */
    public const LABEL_FORMAT = 'Ymd_Hi';

    /** Имя файла новой конвенции: метка, два подчёркивания, слаг. */
    private const NAME_PATTERN = '~^(\d{8}_\d{4})__~';

    /** Допустимый слаг: только то, что одинаково читается на любой файловой системе. */
    private const SLUG_PATTERN = '~^[a-z0-9_]+$~';

    private string $dir;

    /** @var callable(): int Источник текущего времени — подменяется в тестах. */
    private $clock;

    private \DateTimeZone $timezone;

    /**
     * @param string             $dir   каталог миграций
     * @param (callable(): int)|null $clock источник времени; по умолчанию `time()`
     * @param string             $timezone часовой пояс меток (идентификатор IANA)
     */
    public function __construct(string $dir, ?callable $clock = null, string $timezone = self::DEFAULT_TIMEZONE)
    {
        try {
            $this->timezone = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Неизвестный часовой пояс меток: ' . $timezone);
        }
        $this->dir = rtrim($dir, '/\\');
        $this->clock = $clock ?: static function (): int {
            return time();
        };
    }

    /**
     * Привести слаг к виду, пригодному для имени файла.
     *
     * Транслита намеренно нет: слаги миграций пишут разработчики, а транслитерация
     * одного и того же слова разными таблицами даёт разные имена — для ключа журнала
     * это хуже, чем отказ с подсказкой.
     *
     * @throws \InvalidArgumentException если после нормализации остались чужие символы
     */
    public static function normalizeSlug(string $slug): string
    {
        $normalized = strtolower(trim($slug));
        $normalized = (string)preg_replace('~[\s\-]+~u', '_', $normalized);
        $normalized = trim($normalized, '_');

        if ($normalized === '') {
            throw new \InvalidArgumentException('Слаг миграции пуст — укажите, что она делает.');
        }

        if (!preg_match(self::SLUG_PATTERN, $normalized)) {
            throw new \InvalidArgumentException(
                'В слаге миграции допустимы только латиница, цифры и подчёркивание,'
                . ' а получено «' . $slug . '». Например: drop_stale_orders.'
            );
        }

        return $normalized;
    }

    /**
     * Метка для новой миграции — текущее время в часовом поясе проекта.
     */
    public function nextLabel(): string
    {
        return (new \DateTimeImmutable('@' . ($this->clock)()))
            ->setTimezone($this->timezone)
            ->format(self::LABEL_FORMAT);
    }

    /**
     * Имя файла, которое получит новая миграция.
     */
    public function nextName(string $slug, string $ext = 'php'): string
    {
        return $this->nextLabel() . '__' . self::normalizeSlug($slug) . '.' . $ext;
    }

    /**
     * Создать файл миграции и вернуть путь к нему.
     *
     * @throws \RuntimeException если файл уже существует или не записался
     */
    public function write(string $slug, string $body, string $ext = 'php'): string
    {
        return $this->writeNamed($this->nextName($slug, $ext), $body);
    }

    /** Создать файл с уже выбранным генератором именем. */
    public function writeNamed(string $name, string $body): string
    {
        if (basename($name) !== $name || !preg_match('~^\d{8}_\d{4}__[a-z0-9_]+\.php$~', $name)) {
            throw new \InvalidArgumentException('Недопустимое имя миграции: ' . $name);
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Не удалось создать каталог миграций: ' . $this->dir);
        }

        $path = $this->dir . '/' . $name;

        /* Перезаписать существующий файл нельзя даже теоретически: он мог быть уже
           применён, и его содержимое зафиксировано checksum в журнале. */
        if (file_exists($path)) {
            throw new \RuntimeException('Файл миграции уже существует: ' . $path . '. Выберите другой слаг или повторите через минуту.');
        }

        if (@file_put_contents($path, $body) === false) {
            throw new \RuntimeException('Не удалось создать файл миграции: ' . $path);
        }

        return $path;
    }

    /**
     * Дописать операцию в тело уже созданной миграции.
     *
     * Единица миграции — одна причина изменения, а не одно изменение базы: `20260806_2100`
     * правит и тип колонки, и индекс, потому что обе правки нашлись одной сверкой. Собрать
     * такое в один файл должно быть проще, чем копировать блок руками.
     *
     * Операции разделяются комментарием, а `use` из нового тела переносятся в шапку файла,
     * если их там ещё нет.
     *
     * @throws \RuntimeException если файл не найден или его тело не разобрать
     */
    public function append(string $path, string $body, string $comment = ''): string
    {
        $target = @file_get_contents($path);
        if ($target === false) {
            throw new \RuntimeException('Не удалось прочитать миграцию: ' . $path);
        }

        $operation = $this->extractOperation($body);
        $closing = strrpos($target, '};');
        if ($closing === false) {
            throw new \RuntimeException(
                'В ' . basename($path)
                . ' не найдено тело `return function (\\MODX\\Revolution\\modX $modx): void { … };`'
                . ' — допишите операцию руками.'
            );
        }

        $divider = $comment !== '' ? '    // --- ' . $comment . ' ---' . "\n" : '';
        $merged = substr($target, 0, $closing) . "\n" . $divider . $operation . "\n" . substr($target, $closing);
        $merged = $this->mergeUseStatements($merged, $body);

        if (@file_put_contents($path, $merged) === false) {
            throw new \RuntimeException('Не удалось записать миграцию: ' . $path);
        }

        return $path;
    }

    /**
     * Тело функции из сгенерированного файла — без обёртки `return function … {};`.
     */
    private function extractOperation(string $body): string
    {
        $start = strpos($body, "void {\n");
        $end = strrpos($body, '};');

        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException('Сгенерированное тело не разобрать — рецепт вернул неожиданный вид.');
        }

        $start += strlen("void {\n");

        return rtrim(substr($body, $start, $end - $start), "\n");
    }

    /**
     * Перенести `use` из нового тела в шапку целевого файла, не плодя дубликаты.
     */
    private function mergeUseStatements(string $target, string $body): string
    {
        if (!preg_match_all('~^use [^;]+;$~m', $body, $matches)) {
            return $target;
        }

        $missing = array_values(array_filter($matches[0], static function (string $use) use ($target): bool {
            return strpos($target, $use) === false;
        }));

        if (!$missing) {
            return $target;
        }

        $anchor = strpos($target, 'return function');
        if ($anchor === false) {
            return $target;
        }

        return substr($target, 0, $anchor) . implode("\n", $missing) . "\n\n" . substr($target, $anchor);
    }
}

<?php

/**
 * Создание файлов миграций: имя и запись на диск.
 *
 * Единственное место, где решается, как называется новая миграция. До этой задачи
 * метку выбирали руками (и получили восемь файлов `20260806_1236`), а генератор
 * удаления пакетов брал голое `date('Ymd_Hi')` — то есть ровно то же самое, только
 * автоматически.
 *
 * Правило метки: `max(текущее время, последняя метка в каталоге + минута)`.
 * Взять просто текущее время нельзя — консольный PHP работает в UTC, а метки в
 * каталоге ставились по московскому времени, и новый файл встал бы в очередь ПЕРЕД
 * уже применёнными. Правило «не раньше последней» снимает разом и часовой пояс, и
 * два вызова в одну минуту, и сбитые часы машины.
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
    /** Метка времени в имени файла: `YYYYMMDD_HHMM`. */
    public const LABEL_FORMAT = 'Ymd_Hi';

    /** Имя файла новой конвенции: метка, два подчёркивания, слаг. */
    private const NAME_PATTERN = '~^(\d{8}_\d{4})__~';

    /** Допустимый слаг: только то, что одинаково читается на любой файловой системе. */
    private const SLUG_PATTERN = '~^[a-z0-9_]+$~';

    private string $dir;

    /** @var callable(): int Источник текущего времени — подменяется в тестах. */
    private $clock;

    /**
     * @param string             $dir   каталог миграций
     * @param (callable(): int)|null $clock источник времени; по умолчанию `time()`
     */
    public function __construct(string $dir, ?callable $clock = null)
    {
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
     * Последняя метка в каталоге или null, если файлов новой конвенции нет.
     *
     * Файлы, написанные до раннера (`add_*.sql`), под конвенцию не подходят и в
     * расчёте не участвуют.
     */
    public function lastLabel(): ?string
    {
        $last = null;
        foreach ((array)@scandir($this->dir) as $entry) {
            if (!is_string($entry) || !preg_match(self::NAME_PATTERN, $entry, $match)) {
                continue;
            }
            if ($last === null || strcmp($match[1], $last) > 0) {
                $last = $match[1];
            }
        }

        return $last;
    }

    /**
     * Метка для новой миграции — гарантированно больше всех имеющихся.
     */
    public function nextLabel(): string
    {
        $now = date(self::LABEL_FORMAT, ($this->clock)());
        $last = $this->lastLabel();

        if ($last === null || strcmp($now, $last) > 0) {
            return $now;
        }

        return $this->increment($last);
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
            throw new \RuntimeException('Файл миграции уже существует: ' . $path);
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

    /**
     * Следующая минута после метки.
     *
     * Считается через `mktime`, поэтому переполнение нормализуется само: у метки
     * `20260806_2400` (а такая в каталоге есть — 24:00 не существует как время)
     * следующей окажется `20260807_0001`.
     */
    private function increment(string $label): string
    {
        $year = (int)substr($label, 0, 4);
        $month = (int)substr($label, 4, 2);
        $day = (int)substr($label, 6, 2);
        $hour = (int)substr($label, 9, 2);
        $minute = (int)substr($label, 11, 2);

        return date(self::LABEL_FORMAT, (int)mktime($hour, $minute + 1, 0, $month, $day, $year));
    }
}

<?php

/**
 * Шаблоны тел миграций для `db:migration-new`.
 *
 * Рецепты отобраны по фактической истории 33 миграций проекта: чистка настроек,
 * таблиц и элементов встречалась по два-три раза, правка колонок и индексов —
 * образец `20260806_2100`, создание своих таблиц из схемы — `20260806_2000`.
 *
 * Код генерируется в файл ЦЕЛИКОМ, а не прячется за общим хелпером. Причина не в
 * читаемости: миграция — замороженная запись того, что было сделано, её checksum
 * лежит в журнале. Если тело живёт в хелпере, то правка хелпера через полгода
 * меняет поведение всех уже применённых миграций при развёртывании нового сервера —
 * файл прежний, checksum прежний, а делает он другое. Найти такое потом почти
 * невозможно.
 *
 * Каждый шаблон обязан быть идемпотентным пошагово, а не целиком: упавшая на третьей
 * операции миграция при следующем `up` запускается заново с самого начала, и первые
 * две должны спокойно сказать «уже сделано».
 */

namespace MxMigrations\Recipe;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class BuiltinTemplates
{
    /** Рецепты и то, что они просят на входе. */
    public const RECIPES = [
        'empty' => 'пустая заготовка',
        'drop-settings' => 'снять настройки по списку ключей',
        'drop-tables' => 'удалить таблицы по списку имён',
        'drop-elements' => 'удалить элементы MODX по списку имён',
        'add-column' => 'добавить колонку',
        'modify-column' => 'изменить тип колонки',
        'drop-column' => 'удалить колонку',
        'add-index' => 'создать индекс',
        'drop-index' => 'удалить индекс',
        'create-tables' => 'создать таблицы компонента из xPDO-схемы',
    ];

    /**
     * Собрать тело миграции.
     *
     * @param array<string, mixed> $params значения ключей команды
     *
     * @throws \InvalidArgumentException если рецепт неизвестен или не хватает данных
     */
    public function render(string $recipe, string $slug, array $params = []): string
    {
        if (!isset(self::RECIPES[$recipe])) {
            throw new \InvalidArgumentException(
                'Неизвестный рецепт «' . $recipe . '». Доступны: ' . implode(', ', array_keys(self::RECIPES)) . '.'
            );
        }

        $method = 'render' . str_replace(' ', '', ucwords(str_replace('-', ' ', $recipe)));

        return $this->header($slug, $params) . $this->{$method}($params) . PHP_EOL;
    }

    /**
     * Шапка файла: что делает миграция и что сделать после применения.
     *
     * Общие требования стоят в каждом файле: миграцию читают перед применением,
     * а документацию генератора — только при создании.
     *
     * @param array<string, mixed> $params
     */
    private function header(string $slug, array $params): string
    {
        $purpose = (string)($params['purpose'] ?? 'опишите, что и зачем делает миграция');

        /* Перенос по ширине докблока: иначе однострочное описание уезжает на 150 символов
           и файл выбивается из остального кода. */
        $purpose = wordwrap($slug . ' — ' . $purpose, 84, PHP_EOL . ' * ');

        return <<<PHP
<?php

/**
 * {$purpose}
 *
 * Требование к телу: миграция обязана переживать повторный запуск. Состояние уже
 * целевое — выйти молча; нет предпосылки, без которой работа теряет смысл, — бросить
 * исключение, а не пропустить (тихий пропуск даёт зелёный `status` при разъехавшейся
 * базе). MODX уже поднят раннером, поднимать его внутри файла нельзя.
 *
 * После применения обновите эталон структуры базы проекта, если он ведётся.
 */


PHP;
    }

    /** @param array<string, mixed> $params */
    private function renderEmpty(array $params): string
    {
        return <<<'PHP'
return function (\MODX\Revolution\modX $modx): void {
    $prefix = (string)$modx->getOption('table_prefix', null, 'modx_');

    // Работа миграции. Печать попадает в колонку `output` журнала.

    echo 'Готово.' . PHP_EOL;
};
PHP;
    }

    /** @param array<string, mixed> $params */
    private function renderDropSettings(array $params): string
    {
        $class = $this->quote((string)($params['class'] ?? '\\MODX\\Revolution\\modSystemSetting'));
        $keys = $this->phpList($this->requireList($params, 'keys', 'ключи настроек'));

        return <<<PHP
return function (\MODX\Revolution\modX \$modx): void {
    \$className = {$class};
    \$keys = {$keys};

    \$total = 0;

    foreach (\$keys as \$key) {
        \$where = ['key' => \$key];
        \$count = (int)\$modx->getCount(\$className, \$where);
        if (\$count === 0) {
            echo sprintf('%s: %s — уже снята.', \$className, \$key) . PHP_EOL;

            continue;
        }

        \$objects = \$modx->getCollection(\$className, \$where);
        foreach (\$objects as \$object) {
            if (!\$object->remove()) {
                throw new RuntimeException(sprintf('Не удалось снять %s (%s).', \$key, \$className));
            }
        }

        /* Пересчёт после удаления: молча пропустить неудачу нельзя — настройка
           осталась бы жить, а миграция считалась бы применённой. */
        \$left = (int)\$modx->getCount(\$className, \$where);
        if (\$left > 0) {
            throw new RuntimeException(sprintf(
                'Настройка %s (%s) не удалилась: осталось записей — %d.',
                \$key,
                \$className,
                \$left
            ));
        }

        \$total += \$count;
        echo sprintf('%s: %s — снято записей %d.', \$className, \$key, \$count) . PHP_EOL;
    }

    if (\$total > 0) {
        \$modx->getCacheManager()->refresh();
    }

    echo sprintf('Готово: удалено записей — %d.', \$total) . PHP_EOL;
};
PHP;
    }

    /** @param array<string, mixed> $params */
    private function renderDropTables(array $params): string
    {
        $tables = $this->phpList($this->requireList($params, 'tables', 'имена таблиц без префикса'));

        return <<<PHP
return function (\MODX\Revolution\modX \$modx): void {
    \$prefix = (string)\$modx->getOption('table_prefix', null, 'modx_');
    \$tables = {$tables};

    \$dropped = 0;

    foreach (\$tables as \$table) {
        \$full = \$prefix . \$table;
        \$statement = \$modx->query('SHOW TABLES LIKE ' . \$modx->quote(\$full));
        if (\$statement === false || \$statement->fetchColumn() === false) {
            echo \$full . ' — таблицы уже нет.' . PHP_EOL;

            continue;
        }

        if (\$modx->exec('DROP TABLE ' . \$modx->escape(\$full)) === false) {
            throw new RuntimeException('Не удалось удалить таблицу ' . \$full);
        }

        echo \$full . ' — удалена.' . PHP_EOL;
        \$dropped++;
    }

    echo sprintf('Готово: удалено таблиц — %d.', \$dropped) . PHP_EOL;
};
PHP;
    }

    /** @param array<string, mixed> $params */
    private function renderDropElements(array $params): string
    {
        $class = $this->quote((string)($params['class'] ?? '\\MODX\\Revolution\\modPlugin'));
        $names = $this->phpList($this->requireList($params, 'names', 'имена элементов'));

        return <<<PHP
return function (\MODX\Revolution\modX \$modx): void {
    \$className = {$class};
    \$names = {$names};

    \$removed = 0;

    foreach (\$names as \$name) {
        \$elements = \$modx->getCollection(\$className, ['name' => \$name]);
        if (!\$elements) {
            echo sprintf('%s «%s» — уже удалён.', \$className, \$name) . PHP_EOL;

            continue;
        }

        foreach (\$elements as \$element) {
            if (!\$element->remove()) {
                throw new RuntimeException(sprintf('Не удалось удалить %s «%s».', \$className, \$name));
            }
            \$removed++;
        }

        echo sprintf('%s «%s» — удалён.', \$className, \$name) . PHP_EOL;
    }

    if (\$removed > 0) {
        \$modx->getCacheManager()->refresh();
    }

    echo sprintf('Готово: удалено элементов — %d.', \$removed) . PHP_EOL;
};
PHP;
    }

    /** @param array<string, mixed> $params */
    private function renderAddColumn(array $params): string
    {
        $table = $this->quote($this->requireString($params, 'table', 'имя таблицы без префикса'));
        $column = $this->quote($this->requireString($params, 'column', 'имя колонки'));
        $type = $this->quote($this->requireString($params, 'type', 'определение колонки, например "TINYINT(1) NOT NULL DEFAULT 0"'));

        return <<<PHP
return function (\MODX\Revolution\modX \$modx): void {
    \$prefix = (string)\$modx->getOption('table_prefix', null, 'modx_');
    \$table = \$prefix . {$table};
    \$column = {$column};
    \$definition = {$type};

    \$statement = \$modx->query(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        . ' AND TABLE_NAME = ' . \$modx->quote(\$table)
        . ' AND COLUMN_NAME = ' . \$modx->quote(\$column)
    );

    if (\$statement !== false && (int)\$statement->fetchColumn() > 0) {
        echo \$column . ' уже есть в ' . \$table . ' — правка не нужна.' . PHP_EOL;

        return;
    }

    \$sql = 'ALTER TABLE ' . \$modx->escape(\$table)
        . ' ADD COLUMN ' . \$modx->escape(\$column) . ' ' . \$definition;
    if (\$modx->exec(\$sql) === false) {
        throw new RuntimeException('Не выполнился запрос: ' . \$sql);
    }

    echo \$column . ' добавлена в ' . \$table . '.' . PHP_EOL;
};
PHP;
    }

    /** @param array<string, mixed> $params */
    private function renderModifyColumn(array $params): string
    {
        $table = $this->quote($this->requireString($params, 'table', 'имя таблицы без префикса'));
        $column = $this->quote($this->requireString($params, 'column', 'имя колонки'));
        $type = $this->requireString($params, 'type', 'новое определение колонки');
        $typeLiteral = $this->quote($type);
        $expected = $this->quote(strtolower($this->columnType($type)));

        return <<<PHP
return function (\MODX\Revolution\modX \$modx): void {
    \$prefix = (string)\$modx->getOption('table_prefix', null, 'modx_');
    \$table = \$prefix . {$table};
    \$column = {$column};
    \$definition = {$typeLiteral};

    /* Сравнение по COLUMN_TYPE, а не по DATA_TYPE: последний считает varchar(5) и
       varchar(10) одним и тем же, и правка длины молча пропускалась бы. */
    \$statement = \$modx->query(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        . ' AND TABLE_NAME = ' . \$modx->quote(\$table)
        . ' AND COLUMN_NAME = ' . \$modx->quote(\$column)
    );
    \$current = \$statement === false ? false : \$statement->fetchColumn();

    if (\$current === false) {
        throw new RuntimeException('Колонки ' . \$column . ' нет в ' . \$table . ' — менять нечего.');
    }

    if (strtolower((string)\$current) === {$expected}) {
        echo \$column . ' уже нужного типа — правка не нужна.' . PHP_EOL;

        return;
    }

    \$sql = 'ALTER TABLE ' . \$modx->escape(\$table)
        . ' MODIFY COLUMN ' . \$modx->escape(\$column) . ' ' . \$definition;
    if (\$modx->exec(\$sql) === false) {
        throw new RuntimeException('Не выполнился запрос: ' . \$sql);
    }

    echo \$column . ': ' . \$current . ' → ' . {$expected} . '.' . PHP_EOL;
};
PHP;
    }

    /** @param array<string, mixed> $params */
    private function renderDropColumn(array $params): string
    {
        $table = $this->quote($this->requireString($params, 'table', 'имя таблицы без префикса'));
        $column = $this->quote($this->requireString($params, 'column', 'имя колонки'));

        return <<<PHP
/*
 * Отката у миграций нет: DDL в MySQL не транзакционен, а данные удалённой колонки
 * не восстановить. Убедитесь, что колонка больше нигде не читается.
 */
return function (\MODX\Revolution\modX \$modx): void {
    \$prefix = (string)\$modx->getOption('table_prefix', null, 'modx_');
    \$table = \$prefix . {$table};
    \$column = {$column};

    \$statement = \$modx->query(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        . ' AND TABLE_NAME = ' . \$modx->quote(\$table)
        . ' AND COLUMN_NAME = ' . \$modx->quote(\$column)
    );

    if (\$statement === false || (int)\$statement->fetchColumn() === 0) {
        echo \$column . ' в ' . \$table . ' уже нет — правка не нужна.' . PHP_EOL;

        return;
    }

    \$sql = 'ALTER TABLE ' . \$modx->escape(\$table) . ' DROP COLUMN ' . \$modx->escape(\$column);
    if (\$modx->exec(\$sql) === false) {
        throw new RuntimeException('Не выполнился запрос: ' . \$sql);
    }

    echo \$column . ' удалена из ' . \$table . '.' . PHP_EOL;
};
PHP;
    }

    /** @param array<string, mixed> $params */
    private function renderAddIndex(array $params): string
    {
        $table = $this->quote($this->requireString($params, 'table', 'имя таблицы без префикса'));
        $name = $this->quote($this->requireString($params, 'name', 'имя индекса'));
        $columns = $this->phpList($this->requireList($params, 'columns', 'колонки индекса'));
        $unique = !empty($params['unique']);
        $keyword = $unique ? 'ADD UNIQUE INDEX ' : 'ADD INDEX ';
        $duplicateCheck = $unique ? $this->uniqueGuard() : '';

        return <<<PHP
return function (\MODX\Revolution\modX \$modx): void {
    \$prefix = (string)\$modx->getOption('table_prefix', null, 'modx_');
    \$table = \$prefix . {$table};
    \$index = {$name};
    \$columns = {$columns};

    \$statement = \$modx->query(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
        . ' AND TABLE_NAME = ' . \$modx->quote(\$table)
        . ' AND INDEX_NAME = ' . \$modx->quote(\$index)
    );

    if (\$statement !== false && (int)\$statement->fetchColumn() > 0) {
        echo \$index . ' уже есть на ' . \$table . ' — правка не нужна.' . PHP_EOL;

        return;
    }
{$duplicateCheck}
    \$escaped = array_map(static function (string \$column) use (\$modx): string {
        return \$modx->escape(\$column);
    }, \$columns);

    \$sql = 'ALTER TABLE ' . \$modx->escape(\$table)
        . ' {$keyword}' . \$modx->escape(\$index) . ' (' . implode(', ', \$escaped) . ')';
    if (\$modx->exec(\$sql) === false) {
        throw new RuntimeException('Не выполнился запрос: ' . \$sql);
    }

    echo \$index . ' создан на ' . \$table . '.' . PHP_EOL;
};
PHP;
    }

    /** @param array<string, mixed> $params */
    private function renderDropIndex(array $params): string
    {
        $table = $this->quote($this->requireString($params, 'table', 'имя таблицы без префикса'));
        $name = $this->quote($this->requireString($params, 'name', 'имя индекса'));

        return <<<PHP
return function (\MODX\Revolution\modX \$modx): void {
    \$prefix = (string)\$modx->getOption('table_prefix', null, 'modx_');
    \$table = \$prefix . {$table};
    \$index = {$name};

    \$statement = \$modx->query(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
        . ' AND TABLE_NAME = ' . \$modx->quote(\$table)
        . ' AND INDEX_NAME = ' . \$modx->quote(\$index)
    );
    if (\$statement === false || (int)\$statement->fetchColumn() === 0) {
        echo \$index . ' на ' . \$table . ' уже нет — правка не нужна.' . PHP_EOL;

        return;
    }

    \$sql = 'ALTER TABLE ' . \$modx->escape(\$table) . ' DROP INDEX ' . \$modx->escape(\$index);
    if (\$modx->exec(\$sql) === false) {
        throw new RuntimeException('Не выполнился запрос: ' . \$sql);
    }

    echo \$index . ' удалён с ' . \$table . '.' . PHP_EOL;
};
PHP;
    }

    /**
     * Проверка дубликатов перед уникальным ключом.
     *
     * Без неё миграция падает уже на проде — именно там, где дубликаты и заводятся.
     * Решение о том, что делать с найденными, за человеком: удалять чужие данные
     * миграция не вправе.
     */
    private function uniqueGuard(): string
    {
        return <<<'PHP'

    /* Уникальный ключ на таблице с дубликатами не встанет. Ищем их заранее,
       чтобы упасть здесь с понятным списком, а не в середине ALTER на проде. */
    $group = implode(', ', array_map(static function (string $column) use ($modx): string {
        return $modx->escape($column);
    }, $columns));
    $duplicates = $modx->query(
        'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $modx->escape($table)
        . ' GROUP BY ' . $group . ' HAVING COUNT(*) > 1) AS d'
    );
    $found = $duplicates === false ? 0 : (int)$duplicates->fetchColumn();
    if ($found > 0) {
        throw new RuntimeException(sprintf(
            'В %s есть %d групп дубликатов по (%s) — уникальный ключ не встанет.'
            . ' Разберитесь с данными и запустите снова.',
            $table,
            $found,
            implode(', ', $columns)
        ));
    }

PHP;
    }

    /** @param array<string, mixed> $params */
    private function renderCreateTables(array $params): string
    {
        $classes = $this->phpList($this->requireList($params, 'classes', 'классы xPDO из схемы компонента'));
        $package = $this->quote($this->requireString($params, 'package', 'PSR-4 пакет модели'));
        $modelPath = $this->quote($this->requireString($params, 'model-path', 'путь к каталогу src модели'));
        $namespacePrefix = $this->quote($this->requireString(
            $params,
            'namespace-prefix',
            'корневой PSR-4 namespace модели'
        ));

        return <<<PHP
return function (\MODX\Revolution\modX \$modx): void {
    \$classes = {$classes};
    \$package = {$package};
    \$modelPath = {$modelPath};
    \$namespacePrefix = {$namespacePrefix};

    if (!\$modx->addPackage(\$package, \$modelPath, null, \$namespacePrefix)) {
        throw new RuntimeException('Не удалось подключить xPDO-пакет ' . \$package . ' из ' . \$modelPath . '.');
    }

    \$manager = \$modx->getManager();
    if (!\$manager) {
        throw new RuntimeException('xPDO manager недоступен — таблицы создать нечем.');
    }

    \$created = 0;
    \$existed = 0;

    foreach (\$classes as \$class) {
        \$table = \$modx->getTableName(\$class);
        if (!\$table) {
            throw new RuntimeException("Класс {\$class} не найден в схеме — проверьте model/schema.");
        }

        \$statement = \$modx->query('SHOW TABLES LIKE ' . \$modx->quote(trim(\$table, '`')));
        if (\$statement !== false && \$statement->fetchColumn() !== false) {
            \$existed++;

            continue;
        }

        if (!\$manager->createObjectContainer(\$class)) {
            throw new RuntimeException("Не удалось создать таблицу для {\$class}.");
        }

        echo '  создана ' . trim(\$table, '`') . PHP_EOL;
        \$created++;
    }

    echo sprintf(
        'Готово: создано %d, уже было %d.',
        \$created,
        \$existed
    ) . PHP_EOL;
};
PHP;
    }

    /**
     * Тип колонки без хвоста атрибутов: `VARCHAR(10) NULL DEFAULT NULL` → `varchar(10)`.
     *
     * Именно это лежит в `information_schema.COLUMN_TYPE`, с ним и сравниваем.
     */
    private function columnType(string $definition): string
    {
        $parts = preg_split('~\s+~', trim($definition)) ?: [];
        $type = (string)($parts[0] ?? '');

        /* `UNSIGNED` и `ZEROFILL` — часть типа, а не атрибут строки. */
        foreach (array_slice($parts, 1) as $part) {
            if (in_array(strtolower($part), ['unsigned', 'zerofill'], true)) {
                $type .= ' ' . strtolower($part);

                continue;
            }
            break;
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, string>
     */
    private function requireList(array $params, string $key, string $hint): array
    {
        $value = $params[$key] ?? null;
        if (is_string($value)) {
            $value = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        }

        if (!is_array($value) || !$value) {
            throw new \InvalidArgumentException("Для этого рецепта нужен ключ --{$key}: {$hint}.");
        }

        return array_map('strval', $value);
    }

    /** @param array<string, mixed> $params */
    private function requireString(array $params, string $key, string $hint): string
    {
        $value = $params[$key] ?? '';
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Для этого рецепта нужен ключ --{$key}: {$hint}.");
        }

        return trim($value);
    }

    /** Строковый литерал PHP. */
    private function quote(string $value): string
    {
        return var_export($value, true);
    }

    /**
     * Список строк как литерал PHP — по строке на элемент, чтобы диффы читались.
     *
     * @param array<int, string> $values
     */
    private function phpList(array $values): string
    {
        if (count($values) === 1) {
            return '[' . $this->quote($values[0]) . ']';
        }

        $lines = array_map(function (string $value): string {
            return '        ' . $this->quote($value) . ',';
        }, $values);

        return "[\n" . implode("\n", $lines) . "\n    ]";
    }
}

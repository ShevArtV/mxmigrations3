# mxMigrations

Расширяемый раннер и генератор миграций для MODX Revolution 3 и PHP 8.1+.

Это отдельная линия пакета для MODX 3. Версия для MODX 2 живёт в
[ShevArtV/mxmigrations](https://github.com/ShevArtV/mxmigrations) и нумеруется как `1.x`;
эта линия живёт в `ShevArtV/mxmigrations3` и нумеруется как `2.x`.

## Возможности

- журнал применённых миграций с SHA-256 checksum;
- состояния `applied`, `baseline`, `failed`;
- защита от одновременного запуска через MySQL `GET_LOCK`;
- отказ при появлении новой миграции раньше уже применённых;
- команды `status`, `up`, `baseline`, `new`;
- синхронизация нескольких XML-схем и команда `model:build`;
- встроенные рецепты типовых DDL-операций и удаления объектов MODX;
- проектные рецепты через `MigrationRecipeProviderInterface`;
- автономный код миграции: после генерации файл не зависит от рецепта.

## Конфигурация проекта

Скопируйте `core/components/mxmigrations/docs/config.example.php` в `core/config/mxmigrations.php`.
Относительные пути считаются от каталога конфига.

```php
return [
    'id' => 'site',
    'modx_root' => dirname(__DIR__, 2),
    'migrations_path' => dirname(__DIR__) . '/migrations',
    'models' => [
        'site' => [
            'schema_path' => dirname(__DIR__) . '/components/site/schema/site.mysql.schema.xml',
            'model_path' => dirname(__DIR__) . '/components/site/src/Model',
        ],
        'minishop3' => [
            'schema_path' => dirname(__DIR__) . '/components/minishop3/schema/minishop3.mysql.schema.xml',
            'model_path' => dirname(__DIR__) . '/components/minishop3/src/Model',
            'overlay' => true,
        ],
    ],
];
```

Таблица журнала и MySQL lock всегда называются `<id>_migrations` с учётом
префикса MODX. `overlay` нужен для стороннего пакета: изменения хранятся в
`migrations/.mxmigrations/model-changes.php` и повторно накладываются после его обновления.

## CLI

```bash
php core/components/mxmigrations/bin/mxmigrations.php --config=core/config/mxmigrations.php recipe:list
php core/components/mxmigrations/bin/mxmigrations.php --config=core/config/mxmigrations.php new add_status --recipe=add-column --table=site_orders --column=status --type="VARCHAR(20) NOT NULL" --apply
php core/components/mxmigrations/bin/mxmigrations.php --config=core/config/mxmigrations.php status --strict
php core/components/mxmigrations/bin/mxmigrations.php --config=core/config/mxmigrations.php up --dry-run
php core/components/mxmigrations/bin/mxmigrations.php --config=core/config/mxmigrations.php up
php core/components/mxmigrations/bin/mxmigrations.php --config=core/config/mxmigrations.php model:build
php core/components/mxmigrations/bin/mxmigrations.php --config=core/config/mxmigrations.php model:build --apply
```

Без `--apply` команда `new` только печатает имя и тело будущего файла.

Для шаблонов колонок и индексов `new` ищет таблицу во всех настроенных схемах.
Если владельцев несколько, укажите `--model=minishop3`. Собственная XML-схема
обновляется сразу; для модели с `overlay` записывается проектное наложение. Затем
примените миграцию командой `up` и только после успеха выполните `model:build --apply`.
Сборщик обновляет `metadata.mysql.php` и содержимое `mysql/`. Основной класс
объекта создаётся только для нового объекта схемы; существующий основной класс
никогда не перезаписывается.

Для `create-tables` в MODX 3 передаются PSR-4-имена модели и классов:

```bash
php core/components/mxmigrations/bin/mxmigrations.php --config=... new create_shop_tables \
  --recipe=create-tables \
  --package='Shop\\Model' \
  --model-path='/var/www/site/core/components/shop/src/' \
  --namespace-prefix='Shop\\' \
  --classes='Shop\\Model\\Order,Shop\\Model\\OrderProduct' \
  --apply
```

## Проектный рецепт

```php
final class SeedWarehouseRecipe implements \MxMigrations\Recipe\MigrationRecipeInterface
{
    public function getName(): string { return 'shop:seed-warehouse'; }
    public function getDescription(): string { return 'добавить склад проекта'; }
    public function getOptions(): array { return ['warehouse' => 'код склада']; }

    public function render(string $migrationName, array $arguments): string
    {
        // Вернуть полный PHP-файл с
        // `return function (\MODX\Revolution\modX $modx): void { ... };`.
    }
}
```

Провайдер реализует `MigrationRecipeProviderInterface::getRecipes()` и возвращает
массив объектов рецептов. Проектные имена рекомендуется начинать с `<project>:`.
Повторная регистрация имени считается ошибкой.

## Правило автономности

Рецепт используется только во время генерации. Он обязан записать всю логику в
создаваемый файл: миграция не должна вызывать изменяемые helper-методы пакета или
проекта. Благодаря этому обновление генератора не меняет поведение старых миграций.

## Разработка

```bash
composer install
composer test
```

## Сборка transport

```bash
modxapp build mxmigrations
```

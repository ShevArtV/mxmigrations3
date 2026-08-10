<?php

/**
 * Тесты шаблонов миграций (#2608-40).
 *
 * Главное, что проверяется: сгенерированный файл — валидный PHP, возвращающий
 * callable нужной сигнатуры. Ошибка в шаблоне иначе всплыла бы только в момент
 * применения миграции на сервере.
 */

namespace MxMigrations\Tests\Unit\Recipe;

use PHPUnit\Framework\TestCase;
use MxMigrations\Recipe\BuiltinTemplates;

class BuiltinTemplatesTest extends TestCase
{
    /** @var array<int, string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->written = [];
    }

    /**
     * @dataProvider recipes
     *
     * @param array<string, mixed> $params
     */
    public function testEveryRecipeProducesValidPhp(string $recipe, array $params): void
    {
        $body = (new BuiltinTemplates())->render($recipe, '20260807_1030__example.php', $params);

        $this->assertSyntaxValid($body, $recipe);
        $this->assertStringContainsString('эталон структуры базы проекта', $body);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public function recipes(): array
    {
        return [
            'empty' => ['empty', []],
            'drop-settings' => ['drop-settings', ['keys' => 'warehouse_handler,show_dap_warning']],
            'drop-settings контекстные' => ['drop-settings', [
                'keys' => 'a',
                'class' => '\\MODX\\Revolution\\modContextSetting',
            ]],
            'drop-tables' => ['drop-tables', ['tables' => 'sleepandglow_remains']],
            'drop-elements' => ['drop-elements', [
                'names' => 'OldPlugin',
                'class' => '\\MODX\\Revolution\\modPlugin',
            ]],
            'add-column' => ['add-column', [
                'table' => 'sleepandglow_reviews',
                'column' => 'is_pinned',
                'type' => 'TINYINT(1) NOT NULL DEFAULT 0',
            ]],
            'modify-column' => ['modify-column', [
                'table' => 'sleepandglow_delivery_countries',
                'column' => 'phone_code',
                'type' => 'VARCHAR(10) NULL DEFAULT NULL',
            ]],
            'drop-column' => ['drop-column', ['table' => 'sleepandglow_reviews', 'column' => 'old_flag']],
            'add-index' => ['add-index', [
                'table' => 'site_content',
                'name' => 'idx_product_filter',
                'columns' => 'class_key,context_key,published',
            ]],
            'add-index уникальный' => ['add-index', [
                'table' => 'sleepandglow_warehouses',
                'name' => 'code',
                'columns' => 'code',
                'unique' => true,
            ]],
            'drop-index' => ['drop-index', ['table' => 'site_content', 'name' => 'idx_product_filter']],
            'create-tables' => ['create-tables', [
                'package' => 'Example\\Model',
                'model-path' => '/var/www/core/components/example/src/',
                'namespace-prefix' => 'Example\\',
                'classes' => 'Example\\Model\\Review,Example\\Model\\Warehouse',
            ]],
        ];
    }

    /** Тело возвращает именно callable — раннер вызывает его с $modx. */
    public function testRenderedMigrationReturnsCallable(): void
    {
        $body = (new BuiltinTemplates())->render('empty', '20260807_1030__example.php');
        $path = $this->write($body);

        $migration = require $path;

        $this->assertIsCallable($migration);
    }

    /** Сравнение типа идёт по COLUMN_TYPE, иначе varchar(5) и varchar(10) неразличимы. */
    public function testModifyColumnComparesFullColumnType(): void
    {
        $body = (new BuiltinTemplates())->render('modify-column', '20260807_1030__example.php', [
            'table' => 'sleepandglow_delivery_countries',
            'column' => 'phone_code',
            'type' => 'VARCHAR(10) NULL DEFAULT NULL',
        ]);

        $this->assertStringContainsString('COLUMN_TYPE', $body);
        $this->assertStringContainsString("'varchar(10)'", $body);
    }

    /** У уникального индекса перед созданием ищутся дубликаты. */
    public function testUniqueIndexChecksDuplicatesFirst(): void
    {
        $params = ['table' => 'sleepandglow_warehouses', 'name' => 'code', 'columns' => 'code'];
        $templates = new BuiltinTemplates();

        $plain = $templates->render('add-index', '20260807_1030__a.php', $params);
        $unique = $templates->render('add-index', '20260807_1030__b.php', $params + ['unique' => true]);

        $this->assertStringNotContainsString('дубликат', $plain);
        $this->assertStringContainsString('ADD UNIQUE INDEX', $unique);
        $this->assertStringContainsString('HAVING COUNT(*) > 1', $unique);
    }

    public function testModx3RecipesUseNamespacedApi(): void
    {
        $templates = new BuiltinTemplates();
        $settings = $templates->render('drop-settings', '20260807_1030__settings.php', ['keys' => 'old']);
        $tables = $templates->render('create-tables', '20260807_1031__tables.php', [
            'package' => 'Example\\Model',
            'model-path' => '/var/www/core/components/example/src/',
            'namespace-prefix' => 'Example\\',
            'classes' => 'Example\\Model\\Review',
        ]);

        $this->assertStringContainsString('\\MODX\\Revolution\\modX $modx', $settings);
        $this->assertStringContainsString(var_export('\\MODX\\Revolution\\modSystemSetting', true), $settings);
        $this->assertStringContainsString('getCacheManager()->refresh()', $settings);
        $this->assertStringContainsString('addPackage($package, $modelPath, null, $namespacePrefix)', $tables);
    }

    public function testUnknownRecipeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BuiltinTemplates())->render('drop-database', '20260807_1030__example.php');
    }

    public function testRecipeWithoutRequiredParamsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('~--keys~');

        (new BuiltinTemplates())->render('drop-settings', '20260807_1030__example.php');
    }

    private function assertSyntaxValid(string $body, string $recipe): void
    {
        $path = $this->write($body);
        $output = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, "Рецепт «{$recipe}» дал невалидный PHP: " . implode("\n", $output));
    }

    private function write(string $body): string
    {
        $path = sys_get_temp_dir() . '/sg-template-' . uniqid('', true) . '.php';
        file_put_contents($path, $body);
        $this->written[] = $path;

        return $path;
    }
}

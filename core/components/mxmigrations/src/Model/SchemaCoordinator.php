<?php

namespace MxMigrations\Model;

use MxMigrations\Config\ModelConfig;
use MxMigrations\Config\ProjectConfig;

final class SchemaCoordinator
{
    private const RECIPES = ['add-column', 'modify-column', 'drop-column', 'add-index', 'drop-index'];

    private ProjectConfig $config;
    private ModelChangeStore $store;

    public function __construct(ProjectConfig $config)
    {
        $this->config = $config;
        $this->store = new ModelChangeStore($config->getModelChangesPath());
    }

    /** @param array<string, mixed> $arguments
     *  @return array{model: ModelConfig, change: array<string, mixed>}|null
     */
    public function prepare(string $migration, string $recipe, array $arguments): ?array
    {
        if (!in_array($recipe, self::RECIPES, true) || empty($arguments['table']) || !$this->config->getModels()) {
            return null;
        }
        $requested = isset($arguments['model']) ? (string)$arguments['model'] : '';
        $matches = [];
        foreach ($this->config->getModels() as $name => $model) {
            if ($requested !== '' && $requested !== $name) { continue; }
            $schema = new SchemaDocument($model->getSchemaPath());
            $class = $schema->classForTable((string)$arguments['table']);
            if ($class !== null) { $matches[] = [$model, $class]; }
        }
        if ($requested !== '' && !$matches) {
            throw new \RuntimeException('Таблица ' . $arguments['table'] . ' не найдена в модели «' . $requested . '».');
        }
        if (!$matches) { return null; }
        if (count($matches) > 1) {
            throw new \RuntimeException('Таблица найдена в нескольких моделях — укажите --model=<имя>.');
        }
        /** @var ModelConfig $model */
        [$model, $class] = $matches[0];
        $change = [
            'migration' => $migration,
            'model' => $model->getName(),
            'recipe' => $recipe,
            'table' => (string)$arguments['table'],
            'class' => $class,
        ];
        foreach (['column', 'type', 'name'] as $key) {
            if (isset($arguments[$key])) { $change[$key] = (string)$arguments[$key]; }
        }
        if (isset($arguments['columns'])) {
            $change['columns'] = array_values(array_filter(array_map('trim', explode(',', (string)$arguments['columns']))));
        }
        if (!empty($arguments['unique'])) { $change['unique'] = true; }

        $schema = new SchemaDocument($model->getSchemaPath());
        if ($model->usesOverlay()) {
            foreach ($this->store->forModel($model->getName()) as $existing) { $schema->apply($existing); }
        }
        $schema->apply($change);

        return ['model' => $model, 'change' => $change];
    }

    /** @param array{model: ModelConfig, change: array<string, mixed>} $prepared */
    public function commit(array $prepared): void
    {
        $model = $prepared['model'];
        $schemaPath = $model->getSchemaPath();
        $storePath = $this->store->getPath();
        $schemaBackup = !$model->usesOverlay() ? @file_get_contents($schemaPath) : false;
        $storeExisted = is_file($storePath);
        $storeBackup = $storeExisted ? @file_get_contents($storePath) : false;
        try {
            if (!$model->usesOverlay()) {
                $schema = new SchemaDocument($schemaPath);
                $schema->apply($prepared['change']);
                $schema->save($schemaPath);
            }
            $this->store->append($prepared['change']);
        } catch (\Throwable $e) {
            if ($schemaBackup !== false) { @file_put_contents($schemaPath, $schemaBackup); }
            if ($storeExisted && $storeBackup !== false) { @file_put_contents($storePath, $storeBackup); }
            elseif (!$storeExisted && is_file($storePath)) { @unlink($storePath); }
            throw $e;
        }
    }

    public function getStore(): ModelChangeStore { return $this->store; }
}

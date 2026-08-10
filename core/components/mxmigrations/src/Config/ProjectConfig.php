<?php

namespace MxMigrations\Config;

final class ProjectConfig
{
    private string $id;
    private string $migrationsPath;
    private string $modxRoot;
    /** @var array<string, ModelConfig> */
    private array $models;
    /** @var array<int, string> */
    private array $recipeProviders;
    /** @var array<int, string> */
    private array $autoload;

    /** @param array<string, mixed> $data */
    public function __construct(array $data, string $baseDir)
    {
        $this->id = $this->identifier((string)($data['id'] ?? ''), 'id');
        $this->migrationsPath = $this->path((string)($data['migrations_path'] ?? ''), $baseDir);
        $this->modxRoot = $this->path((string)($data['modx_root'] ?? $baseDir), $baseDir);
        $this->models = [];
        foreach ((array)($data['models'] ?? []) as $name => $model) {
            if (!is_array($model)) {
                throw new \InvalidArgumentException('Описание модели ' . $name . ' должно быть массивом.');
            }
            $name = $this->identifier((string)$name, 'models');
            $this->models[$name] = new ModelConfig(
                $name,
                $this->path((string)($model['schema_path'] ?? ''), $baseDir),
                $this->path((string)($model['model_path'] ?? ''), $baseDir),
                !empty($model['overlay'])
            );
        }
        $this->recipeProviders = array_values(array_map('strval', (array)($data['recipe_providers'] ?? [])));
        $this->autoload = [];
        foreach ((array)($data['autoload'] ?? []) as $path) {
            $this->autoload[] = $this->path((string)$path, $baseDir);
        }
    }

    public static function fromFile(string $path): self
    {
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new \RuntimeException('Конфиг миграций не найден: ' . $path);
        }
        $data = require $real;
        if (!is_array($data)) {
            throw new \RuntimeException('Конфиг миграций должен возвращать массив: ' . $real);
        }

        return new self($data, dirname($real));
    }

    public function getId(): string { return $this->id; }
    public function getMigrationsPath(): string { return $this->migrationsPath; }
    public function getJournalTable(): string { return $this->id . '_migrations'; }
    public function getLockName(): string { return $this->id . '_migrations'; }
    public function getModxRoot(): string { return $this->modxRoot; }
    /** @return array<string, ModelConfig> */
    public function getModels(): array { return $this->models; }
    public function getModel(string $name): ModelConfig
    {
        if (!isset($this->models[$name])) {
            throw new \InvalidArgumentException('Модель «' . $name . '» не настроена.');
        }

        return $this->models[$name];
    }
    public function getModelChangesPath(): string
    {
        return $this->migrationsPath . '/.mxmigrations/model-changes.php';
    }
    /** @return array<int, string> */
    public function getRecipeProviders(): array { return $this->recipeProviders; }
    /** @return array<int, string> */
    public function getAutoload(): array { return $this->autoload; }

    private function identifier(string $value, string $key): string
    {
        if (!preg_match('~^[a-zA-Z][a-zA-Z0-9_]*$~', $value)) {
            throw new \InvalidArgumentException($key . ' должен содержать только латиницу, цифры и подчёркивание.');
        }

        return $value;
    }

    private function path(string $path, string $baseDir): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Путь в конфиге не может быть пустым.');
        }
        if ($path[0] !== '/') {
            $path = $baseDir . '/' . $path;
        }

        return rtrim($path, '/\\');
    }
}

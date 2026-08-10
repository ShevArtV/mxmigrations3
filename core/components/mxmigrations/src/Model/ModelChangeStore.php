<?php

namespace MxMigrations\Model;

final class ModelChangeStore
{
    private string $path;

    public function __construct(string $path) { $this->path = $path; }
    public function getPath(): string { return $this->path; }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        if (!is_file($this->path)) { return []; }
        $changes = require $this->path;
        if (!is_array($changes)) {
            throw new \RuntimeException('Реестр изменений моделей должен возвращать массив: ' . $this->path);
        }
        return array_values($changes);
    }

    /** @return array<int, array<string, mixed>> */
    public function forModel(string $model): array
    {
        return array_values(array_filter($this->all(), static function (array $change) use ($model): bool {
            return ($change['model'] ?? '') === $model;
        }));
    }

    /** @param array<string, mixed> $change */
    public function append(array $change): void
    {
        $changes = $this->all();
        $changes[] = $change;
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог: ' . $dir);
        }
        $contents = "<?php\n\nreturn " . var_export($changes, true) . ";\n";
        if (@file_put_contents($this->path, $contents) === false) {
            throw new \RuntimeException('Не удалось записать реестр изменений моделей: ' . $this->path);
        }
    }
}

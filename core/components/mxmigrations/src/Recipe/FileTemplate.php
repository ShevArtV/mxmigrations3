<?php

namespace MxMigrations\Recipe;

final class FileTemplate
{
    /** @param array<string, mixed> $data */
    public static function render(string $path, array $data): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Файл шаблона миграции не найден или недоступен: ' . $path);
        }

        $template = file_get_contents($path);
        if ($template === false) {
            throw new \RuntimeException('Не удалось прочитать файл шаблона миграции: ' . $path);
        }

        preg_match_all('~\{\{([a-zA-Z][a-zA-Z0-9_]*)\}\}~', $template, $matches);
        $placeholders = array_values(array_unique($matches[1] ?? []));

        foreach ($placeholders as $name) {
            if (!array_key_exists($name, $data)) {
                throw new \InvalidArgumentException('Не задан плейсхолдер {{' . $name . '}} для шаблона ' . $path);
            }
        }
        foreach (array_keys($data) as $name) {
            if (!is_string($name) || !in_array($name, $placeholders, true)) {
                throw new \InvalidArgumentException('Лишний плейсхолдер «' . $name . '» для шаблона ' . $path);
            }
        }

        $replace = [];
        foreach ($placeholders as $name) {
            $value = $data[$name];
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Значение {{' . $name . '}} должно быть строкой, числом, bool или null.');
            }
            $replace['{{' . $name . '}}'] = (string)$value;
        }

        return strtr($template, $replace);
    }
}

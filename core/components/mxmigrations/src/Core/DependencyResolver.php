<?php

/**
 * Порядок неприменённых миграций с учётом `@depends`.
 *
 * Порядок — топологический по зависимостям, внутри — по имени файла (без `@depends`
 * получается обычная сортировка по имени). Зависимость считается выполненной, если
 * она уже применена (есть запись или файл признан переименованием) или входит в
 * текущий прогон. Ошибка — когда зависимости нет вовсе, она есть в каталоге, но не
 * применена и в прогон не входит, либо зависимости образуют цикл.
 */

namespace MxMigrations\Core;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class DependencyResolver
{
    /**
     * @param array<int, MigrationFile> $pending   неприменённые файлы
     * @param array<string, bool>       $satisfied имена уже применённых миграций
     * @param array<string, bool>       $known     имена всех файлов каталога
     *
     * @return array{ordered: array<int, MigrationFile>, errors: array<int, string>}
     */
    public function resolve(array $pending, array $satisfied, array $known): array
    {
        $byName = [];
        foreach ($pending as $file) {
            $byName[$file->getName()] = $file;
        }
        ksort($byName, SORT_STRING);

        $errors = [];
        $edges = [];
        foreach ($byName as $name => $file) {
            $edges[$name] = [];
            foreach ($file->getDepends() as $dependency) {
                if ($dependency === $name) {
                    $errors[] = 'Миграция ' . $name . ' зависит от самой себя.';
                } elseif (isset($byName[$dependency])) {
                    $edges[$name][] = $dependency;
                } elseif (isset($satisfied[$dependency])) {
                    continue;
                } elseif (isset($known[$dependency])) {
                    $errors[] = 'Миграция ' . $name . ' зависит от ' . $dependency
                        . ', которая не применена и не входит в текущий прогон.';
                } else {
                    $errors[] = 'Миграция ' . $name . ' зависит от ' . $dependency
                        . ', но такой миграции нет ни в каталоге, ни в журнале.';
                }
            }
        }

        $ordered = [];
        $done = [];
        $left = $byName;
        while ($left) {
            $next = null;
            foreach ($left as $name => $file) {
                if (!array_diff($edges[$name], array_keys($done))) {
                    $next = $name;
                    break;
                }
            }
            if ($next === null) {
                $errors[] = 'Циклическая зависимость миграций: ' . implode(' -> ', $this->cycle($left, $edges)) . '.';
                foreach ($left as $file) {
                    $ordered[] = $file;
                }
                break;
            }
            $ordered[] = $left[$next];
            $done[$next] = true;
            unset($left[$next]);
        }

        return ['ordered' => $ordered, 'errors' => $errors];
    }

    /**
     * Один из циклов среди оставшихся узлов: у каждого из них есть неразрешённая
     * зависимость внутри остатка, значит, идя по ней, рано или поздно вернёмся.
     *
     * @param array<string, MigrationFile>      $left
     * @param array<string, array<int, string>> $edges
     *
     * @return array<int, string>
     */
    private function cycle(array $left, array $edges): array
    {
        $path = [];
        $current = (string)array_key_first($left);
        while (!in_array($current, $path, true)) {
            $path[] = $current;
            $current = (string)current(array_filter($edges[$current], static function (string $dep) use ($left): bool {
                return isset($left[$dep]);
            }));
        }
        $path = array_slice($path, (int)array_search($current, $path, true));
        $path[] = $current;

        return $path;
    }
}

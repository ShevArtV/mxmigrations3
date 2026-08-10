<?php

namespace MxMigrations\Model;

final class ModelFilesystem
{
    /** @return array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>} */
    public function diff(string $currentDir, string $builtDir, callable $managed): array
    {
        $current = $this->files($currentDir);
        $built = $this->files($builtDir);
        $result = ['added' => [], 'changed' => [], 'removed' => []];
        foreach ($built as $relative => $path) {
            if (!isset($current[$relative])) { $result['added'][] = $relative; }
            elseif ($managed($relative, $current[$relative], $path)
                && file_get_contents($path) !== file_get_contents($current[$relative])) {
                $result['changed'][] = $relative;
            }
        }
        foreach (array_keys($current) as $relative) {
            if (!isset($built[$relative]) && $managed($relative, $current[$relative], null)) {
                $result['removed'][] = $relative;
            }
        }
        foreach ($result as &$files) { sort($files); }
        return $result;
    }

    public function apply(string $currentDir, string $builtDir, callable $managed): void
    {
        $built = $this->files($builtDir);
        foreach ($this->files($currentDir) as $relative => $path) {
            if (!isset($built[$relative]) && $managed($relative, $path, null) && !@unlink($path)) {
                throw new \RuntimeException('Не удалось удалить старый файл модели: ' . $path);
            }
        }
        foreach ($built as $relative => $path) {
            $target = rtrim($currentDir, '/\\') . '/' . $relative;
            if (is_file($target) && !$managed($relative, $target, $path)) { continue; }
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Не удалось создать каталог: ' . $dir);
            }
            if (!@copy($path, $target)) { throw new \RuntimeException('Не удалось записать файл модели: ' . $target); }
        }
    }

    public function normalize(string $dir): void
    {
        foreach ($this->files($dir) as $path) {
            $contents = (string)file_get_contents($path);
            $contents = str_replace(["\r\n", "\r"], "\n", $contents);
            $contents = (string)preg_replace('~[ \t]+$~m', '', $contents);
            file_put_contents($path, rtrim($contents, "\n") . "\n");
        }
    }

    public function generatedRoot(string $root, string $package): string
    {
        $expected = rtrim($root, '/\\') . '/' . str_replace('\\', '/', trim($package, '\\'));
        if (is_dir($expected)) { return $expected; }
        $dirs = [];
        foreach ((array)@scandir($root) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($root . '/' . $entry)) { $dirs[] = $root . '/' . $entry; }
        }
        if (count($dirs) === 1) { return $dirs[0]; }
        if ($this->files($root)) { return $root; }
        throw new \RuntimeException('Не удалось определить каталог сгенерированной модели для package=' . $package . '.');
    }

    public function removeTree(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
        @rmdir($dir);
    }

    /** @return array<string, string> */
    private function files(string $dir): array
    {
        if (!is_dir($dir)) { return []; }
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') { continue; }
            $relative = ltrim(str_replace(rtrim($dir, '/\\'), '', $file->getPathname()), '/\\');
            $found[$relative] = $file->getPathname();
        }
        ksort($found);
        return $found;
    }
}

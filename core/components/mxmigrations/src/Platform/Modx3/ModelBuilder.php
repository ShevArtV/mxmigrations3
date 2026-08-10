<?php

namespace MxMigrations\Platform\Modx3;

use MODX\Revolution\modX;
use MxMigrations\Model\ModelFilesystem;
use MxMigrations\Model\SchemaDocument;

final class ModelBuilder
{
    private modX $modx;
    private ModelFilesystem $files;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
        $this->files = new ModelFilesystem();
    }

    /** @return array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>} */
    public function build(string $schemaPath, string $modelPath, bool $apply): array
    {
        $root = sys_get_temp_dir() . '/mxmigrations-model-' . getmypid() . '-' . bin2hex(random_bytes(4));
        try {
            if (!@mkdir($root, 0775, true) && !is_dir($root)) { throw new \RuntimeException('Не удалось создать ' . $root); }
            $manager = $this->modx->getManager();
            $generator = $manager ? $manager->getGenerator() : null;
            if (!$generator) { throw new \RuntimeException('xPDO generator недоступен.'); }

            $package = (new SchemaDocument($schemaPath))->getPackage();
            $parts = array_values(array_filter(explode('\\', trim($package, '\\'))));
            $relative = array_pop($parts) ?: '';
            $namespacePrefix = implode('\\', $parts);
            $generator->parseSchema($schemaPath, $root . '/', [
                'compile' => 0,
                'update' => 0,
                'regenerate' => 1,
                'namespacePrefix' => $namespacePrefix === '' ? '' : $namespacePrefix . '\\',
            ]);
            $this->files->normalize($root);
            $built = $relative !== '' && is_dir($root . '/' . $relative)
                ? $root . '/' . $relative
                : $this->files->generatedRoot($root, $package);
            $managed = static function (string $relative): bool {
                $relative = str_replace('\\', '/', strtolower($relative));
                return strpos($relative, 'mysql/') === 0
                    || strpos(basename($relative), 'metadata.') === 0;
            };
            $diff = $this->files->diff($modelPath, $built, $managed);
            if ($apply) { $this->files->apply($modelPath, $built, $managed); }
            return $diff;
        } finally {
            $this->files->removeTree($root);
        }
    }
}

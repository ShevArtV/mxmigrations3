<?php

/**
 * Выполнение миграций в контексте MODX.
 *
 * Две ветки исполнения:
 *   1. `.php` новой конвенции — файл возвращает
 *      `callable(\MODX\Revolution\modX $modx)`, раннер его
 *      вызывает: MODX уже поднят, второй бутстрап не нужен;
 *   2. `.php` старого образца, который сам делает `define('MODX_API_MODE'); require
 *      index.php` — запускается отдельным процессом, потому что повторный require
 *      ядра в уже работающем процессе роняет всё на переобъявлении классов.
 *
 * Ветки `.sql` больше нет: за всё время работы раннера её не использовала
 * ни одна миграция — идемпотентность, которой раннер требует, в SQL почти невыразима.
 */

namespace MxMigrations\Platform\Modx3;

use MODX\Revolution\modX;
use MxMigrations\Core\ExecutorInterface;
use MxMigrations\Core\MigrationFile;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class ModxExecutor implements ExecutorInterface
{
    private modX $modx;
    private string $phpBinary;

    public function __construct(modX $modx, string $phpBinary = PHP_BINARY)
    {
        $this->modx = $modx;
        $this->phpBinary = $phpBinary;
    }

    public function execute(MigrationFile $file): string
    {
        return $file->isLegacyBootstrap()
            ? $this->executeLegacyPhp($file)
            : $this->executePhp($file);
    }

    private function executePhp(MigrationFile $file): string
    {
        $modx = $this->modx;

        ob_start();
        try {
            /** @var mixed $migration */
            $migration = require $file->getPath();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new \RuntimeException('Ошибка при подключении миграции: ' . $e->getMessage(), 0, $e);
        }
        $output = (string)ob_get_clean();

        if (!is_callable($migration)) {
            throw new \RuntimeException(
                'PHP-миграция должна возвращать callable вида '
                . '`return function (\\MODX\\Revolution\\modX $modx): void {...};`. '
                . 'Файл ничего не вернул — см. migrations/README.md.'
            );
        }

        ob_start();
        try {
            $migration($modx);
        } catch (\Throwable $e) {
            $output .= (string)ob_get_clean();
            throw new \RuntimeException(trim($output . PHP_EOL . $e->getMessage()), 0, $e);
        }

        return trim($output . (string)ob_get_clean());
    }

    private function executeLegacyPhp(MigrationFile $file): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$this->phpBinary, $file->getPath()],
            $descriptors,
            $pipes,
            (string)$this->modx->getOption('base_path')
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить дочерний процесс PHP для legacy-миграции.');
        }

        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $output = trim($stdout . ($stderr !== '' ? PHP_EOL . $stderr : ''));

        if ($code !== 0) {
            throw new \RuntimeException(sprintf(
                'Legacy-миграция завершилась с кодом %d.%s%s',
                $code,
                PHP_EOL,
                $output
            ));
        }

        return $output;
    }

}

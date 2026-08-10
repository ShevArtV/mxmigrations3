<?php

namespace MODX\Revolution {
    /** Минимальный MODX 3 double: пакет не тянет ядро как dev-зависимость. */
    class modX
    {
        /** @var array<string, mixed> */
        public array $options = [
            'base_path' => '/tmp/',
            'dbname' => 'mxmigrations_test',
            'table_prefix' => 'modx_',
        ];

        /** @var array<int, string> */
        public array $execSql = [];

        /** @var array<int, \MxMigrations\Tests\Unit\Platform\StatementDouble> */
        public array $statements = [];

        /** @param mixed $options @param mixed $default
         *  @return mixed
         */
        public function getOption(string $key, $options = null, $default = null)
        {
            return $this->options[$key] ?? $default;
        }

        public function exec(string $sql): int
        {
            $this->execSql[] = $sql;

            return 0;
        }

        public function prepare(string $sql): \MxMigrations\Tests\Unit\Platform\StatementDouble
        {
            $statement = new \MxMigrations\Tests\Unit\Platform\StatementDouble($sql);
            $this->statements[] = $statement;

            return $statement;
        }
    }
}

namespace MxMigrations\Tests\Unit\Platform {
    use MODX\Revolution\modX;
    use MxMigrations\Core\MigrationFile;
    use MxMigrations\Platform\Modx3\ModxExecutor;
    use MxMigrations\Platform\Modx3\MysqlLock;
    use MxMigrations\Platform\Modx3\PdoJournal;
    use PHPUnit\Framework\TestCase;

    final class Modx3AdaptersTest extends TestCase
    {
        /** @var array<int, string> */
        private array $files = [];

        protected function tearDown(): void
        {
            foreach ($this->files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        public function testExecutorPassesNamespacedModxToMigration(): void
        {
            $path = $this->migration(<<<'PHP'
<?php
return function (\MODX\Revolution\modX $modx): void {
    echo get_class($modx);
};
PHP
            );

            $output = (new ModxExecutor(new modX()))->execute(new MigrationFile($path));

            $this->assertSame(modX::class, $output);
        }

        public function testMysqlLockUsesDatabaseScopedNameAndReleasesIt(): void
        {
            $modx = new modX();
            $lock = new MysqlLock($modx, 'deploy');

            $this->assertTrue($lock->acquire());
            $lock->release();

            $this->assertSame('SELECT GET_LOCK(:name, 0)', $modx->statements[0]->sql);
            $this->assertSame([':name' => 'mxmigrations_test:deploy'], $modx->statements[0]->parameters);
            $this->assertSame('SELECT RELEASE_LOCK(:name)', $modx->statements[1]->sql);
        }

        public function testJournalCreatesPrefixedUtf8mb4Table(): void
        {
            $modx = new modX();

            (new PdoJournal($modx, 'project_migrations'))->ensureTable();

            $this->assertStringContainsString('`modx_project_migrations`', $modx->execSql[0]);
            $this->assertStringContainsString('CHARSET=utf8mb4', $modx->execSql[0]);
        }

        private function migration(string $body): string
        {
            $path = sys_get_temp_dir() . '/mxmigrations3-' . uniqid('', true) . '.php';
            file_put_contents($path, $body);
            $this->files[] = $path;

            return $path;
        }
    }

    final class StatementDouble
    {
        public string $sql;
        /** @var array<string, mixed> */
        public array $parameters = [];

        public function __construct(string $sql)
        {
            $this->sql = $sql;
        }

        /** @param array<string, mixed> $parameters */
        public function execute(array $parameters = []): bool
        {
            $this->parameters = $parameters;

            return true;
        }

        public function fetchColumn(): int
        {
            return 1;
        }
    }
}

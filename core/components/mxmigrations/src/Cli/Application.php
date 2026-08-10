<?php

namespace MxMigrations\Cli;

use MODX\Revolution\Error\modError;
use MODX\Revolution\modX;
use MxMigrations\Config\ProjectConfig;
use MxMigrations\Core\MigrationRunner;
use MxMigrations\Core\MigrationWriter;
use MxMigrations\Model\SchemaCoordinator;
use MxMigrations\Model\SchemaDocument;
use MxMigrations\Platform\Modx3\ModelBuilder;
use MxMigrations\Platform\Modx3\ModxExecutor;
use MxMigrations\Platform\Modx3\MysqlLock;
use MxMigrations\Platform\Modx3\PdoJournal;
use MxMigrations\Recipe\MigrationGenerator;
use MxMigrations\Recipe\RecipeRegistry;
use MxMigrations\Recipe\RecipeRegistryFactory;

final class Application
{
    private ProjectConfig $config;
    private RecipeRegistry $recipes;

    public function __construct(ProjectConfig $config)
    {
        $this->config = $config;
        $this->recipes = (new RecipeRegistryFactory())->create($config);
    }

    /** @param array<int, string> $argv */
    public function run(array $argv): int
    {
        [$command, $positionals, $options] = $this->parse($argv);
        switch ($command) {
            case 'recipe:list':
                foreach ($this->recipes->all() as $recipe) {
                    printf("%-32s %s\n", $recipe->getName(), $recipe->getDescription());
                }
                return 0;
            case 'recipe:help':
                $name = $positionals[0] ?? '';
                $recipe = $this->recipes->get($name);
                echo $recipe->getName() . ' — ' . $recipe->getDescription() . PHP_EOL;
                foreach ($recipe->getOptions() as $option => $description) {
                    echo '  --' . $option . '  ' . $description . PHP_EOL;
                }
                return 0;
            case 'new':
                return $this->generate($positionals, $options);
            case 'model:build':
                return $this->buildModels($options);
            case 'status':
                return $this->status($this->runner(), isset($options['strict']));
            case 'up':
                return $this->up($this->runner(), $options);
            case 'baseline':
                $marked = $this->runner()->baseline((string)($options['by'] ?? $this->whoami()));
                echo $marked ? 'Baseline: ' . implode(', ', $marked) . PHP_EOL : 'Нечего помечать.' . PHP_EOL;
                return 0;
            case 'help':
                echo $this->usage();
                return 0;
            default:
                throw new \InvalidArgumentException('Неизвестная команда: ' . $command . PHP_EOL . $this->usage());
        }
    }

    /** @param array<int, string> $positionals @param array<string, mixed> $options */
    private function generate(array $positionals, array $options): int
    {
        $slug = $positionals[0] ?? '';
        if ($slug === '') {
            throw new \InvalidArgumentException('Укажите слаг новой миграции.');
        }
        $recipe = (string)($options['recipe'] ?? 'empty');
        $write = isset($options['apply']);
        $model = isset($options['model']) ? (string)$options['model'] : null;
        unset($options['recipe'], $options['apply'], $options['config'], $options['model']);
        $writer = new MigrationWriter($this->config->getMigrationsPath());
        $result = (new MigrationGenerator(
            $writer,
            $this->recipes
        ))->generate($slug, $recipe, $options, false);
        $schemaArguments = $options;
        if ($model !== null) { $schemaArguments['model'] = $model; }
        $coordinator = new SchemaCoordinator($this->config);
        $prepared = $coordinator->prepare($result['name'], $recipe, $schemaArguments);

        if ($write) {
            $result['path'] = $writer->writeNamed($result['name'], $result['body']);
            try {
                if ($prepared !== null) { $coordinator->commit($prepared); }
            } catch (\Throwable $e) {
                @unlink($result['path']);
                throw $e;
            }
        }

        if ($result['path'] !== null) {
            echo 'Создана миграция: ' . $result['path'] . PHP_EOL;
            if ($prepared !== null) {
                echo 'Изменение модели: ' . $prepared['model']->getName()
                    . ($prepared['model']->usesOverlay() ? ' (проектное наложение)' : ' (XML-схема)') . PHP_EOL;
                echo 'После успешного up выполните model:build --model='
                    . $prepared['model']->getName() . ' --apply.' . PHP_EOL;
            }
        } else {
            echo 'Будет создана: ' . $result['name'] . PHP_EOL . PHP_EOL . $result['body'];
            echo PHP_EOL . 'Для записи добавьте --apply.' . PHP_EOL;
        }
        return 0;
    }

    /** @param array<string, mixed> $options */
    private function buildModels(array $options): int
    {
        $models = $this->config->getModels();
        if (isset($options['model'])) {
            $selected = (string)$options['model'];
            $models = [$selected => $this->config->getModel($selected)];
        }
        if (!$models) { throw new \RuntimeException('В конфиге нет моделей. Добавьте раздел models.'); }

        $modx = $this->modx();
        $plan = $this->runner($modx)->plan();
        $applied = [];
        foreach ($plan['applied'] as $item) { $applied[$item['file']->getName()] = true; }
        $coordinator = new SchemaCoordinator($this->config);
        $builder = new ModelBuilder($modx);
        $write = isset($options['apply']);

        foreach ($models as $name => $model) {
            $changes = $coordinator->getStore()->forModel($name);
            foreach ($changes as $change) {
                if (!isset($applied[(string)$change['migration']])) {
                    throw new \RuntimeException(
                        'Модель ' . $name . ' не собрана: сначала примените миграцию ' . $change['migration'] . '.'
                    );
                }
            }

            $schemaPath = $model->getSchemaPath();
            $temporarySchema = null;
            try {
                if ($model->usesOverlay()) {
                    $temporarySchema = tempnam(sys_get_temp_dir(), 'mxmigrations-schema-');
                    if ($temporarySchema === false) { throw new \RuntimeException('Не удалось создать временную схему.'); }
                    $schema = new SchemaDocument($schemaPath);
                    foreach ($changes as $change) { $schema->apply($change); }
                    $schema->save($temporarySchema);
                    $schemaPath = $temporarySchema;
                }
                $diff = $builder->build($schemaPath, $model->getModelPath(), $write);
            } finally {
                if ($temporarySchema !== null) { @unlink($temporarySchema); }
            }

            echo $name . ':' . PHP_EOL;
            foreach (['added' => 'новый', 'changed' => 'изменён', 'removed' => 'удалён'] as $group => $label) {
                foreach ($diff[$group] as $file) { echo '  ' . $label . ': ' . $file . PHP_EOL; }
            }
            if (!$diff['added'] && !$diff['changed'] && !$diff['removed']) { echo '  модель соответствует схеме' . PHP_EOL; }
        }
        if (!$write) { echo 'Для записи модели добавьте --apply.' . PHP_EOL; }

        return 0;
    }

    private function runner(?modX $modx = null): MigrationRunner
    {
        $modx = $modx ?: $this->modx();
        return new MigrationRunner(
            $this->config->getMigrationsPath(),
            new PdoJournal($modx, $this->config->getJournalTable()),
            new ModxExecutor($modx),
            new MysqlLock($modx, $this->config->getLockName())
        );
    }

    private function status(MigrationRunner $runner, bool $strict): int
    {
        $plan = $runner->plan();
        foreach (['pending', 'drifted', 'missing', 'outOfOrder'] as $group) {
            echo $group . ': ' . count($plan[$group]) . PHP_EOL;
        }
        echo 'applied: ' . count($plan['applied']) . PHP_EOL;
        $problems = $plan['pending'] || $plan['drifted'] || $plan['missing'] || $plan['outOfOrder'];
        return $strict && $problems ? 2 : 0;
    }

    /** @param array<string, mixed> $options */
    private function up(MigrationRunner $runner, array $options): int
    {
        $result = $runner->up(
            isset($options['dry-run']),
            isset($options['ignore-checksum']),
            (string)($options['by'] ?? $this->whoami()),
            isset($options['allow-out-of-order'])
        );
        foreach ($result['executed'] as $migration) {
            echo ($result['dryRun'] ? '[DRY] ' : '[OK] ') . $migration['name'] . PHP_EOL;
        }
        foreach ($result['accepted'] as $name) {
            echo '[ACCEPTED] ' . $name . PHP_EOL;
        }
        if ($result['failed'] !== null) {
            fwrite(STDERR, '[FAILED] ' . $result['failed']['name'] . ': ' . $result['failed']['error'] . PHP_EOL);
            return 1;
        }
        if (!$result['executed'] && !$result['accepted']) {
            echo 'Нечего применять.' . PHP_EOL;
        }
        return 0;
    }

    private function modx(): modX
    {
        $config = $this->config->getModxRoot() . '/config.core.php';
        if (!is_file($config)) {
            throw new \RuntimeException('config.core.php MODX не найден: ' . $config);
        }
        if (!defined('MODX_API_MODE')) {
            define('MODX_API_MODE', true);
        }
        require_once $config;

        $autoload = null;
        foreach ([$this->config->getModxRoot() . '/vendor/autoload.php', MODX_CORE_PATH . 'vendor/autoload.php'] as $candidate) {
            if (is_file($candidate)) {
                $autoload = $candidate;
                break;
            }
        }
        if ($autoload === null) {
            throw new \RuntimeException('Composer autoload MODX не найден ни в корне сайта, ни в core/.');
        }
        require_once $autoload;

        $modx = new modX();
        $modx->initialize('mgr');
        $modx->getService('error', modError::class);
        $modx->setLogLevel(modX::LOG_LEVEL_ERROR);

        return $modx;
    }

    /** @param array<int, string> $argv
     *  @return array{0: string, 1: array<int, string>, 2: array<string, mixed>}
     */
    private function parse(array $argv): array
    {
        $command = 'help';
        $positionals = [];
        $options = [];
        foreach ($argv as $argument) {
            if (strpos($argument, '--') === 0) {
                $pair = explode('=', substr($argument, 2), 2);
                $options[$pair[0]] = $pair[1] ?? true;
            } elseif ($command === 'help') {
                $command = $argument;
            } else {
                $positionals[] = $argument;
            }
        }
        return [$command, $positionals, $options];
    }

    private function whoami(): string
    {
        return trim((string)get_current_user() . '@' . (string)gethostname(), '@');
    }

    private function usage(): string
    {
        return "mxMigrations\n"
            . "  status [--strict]\n  up [--dry-run] [--ignore-checksum] [--allow-out-of-order]\n"
            . "  baseline [--by=name]\n  new <slug> [--recipe=name] [recipe options] [--apply]\n"
            . "  model:build [--model=name] [--apply]\n"
            . "  recipe:list\n  recipe:help <name>\n";
    }
}

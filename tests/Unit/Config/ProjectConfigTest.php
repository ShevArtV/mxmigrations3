<?php

namespace MxMigrations\Tests\Unit\Config;

use MxMigrations\Config\ProjectConfig;
use PHPUnit\Framework\TestCase;

final class ProjectConfigTest extends TestCase
{
    public function testDerivesServiceNamesAndReadsSeveralModels(): void
    {
        $config = new ProjectConfig([
            'id' => 'site',
            'modx_root' => '/srv/site',
            'migrations_path' => '/srv/site/core/migrations',
            'models' => [
                'site' => ['schema_path' => '/srv/site/site.xml', 'model_path' => '/srv/site/model/site'],
                'minishop2' => [
                    'schema_path' => '/srv/site/minishop2.xml',
                    'model_path' => '/srv/site/model/minishop2',
                    'overlay' => true,
                ],
            ],
        ], '/srv/site');

        self::assertSame('site_migrations', $config->getJournalTable());
        self::assertSame('site_migrations', $config->getLockName());
        self::assertCount(2, $config->getModels());
        self::assertTrue($config->getModel('minishop2')->usesOverlay());
        self::assertFalse($config->getModel('site')->usesOverlay());
    }
}


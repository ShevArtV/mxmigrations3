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

    /** @return array<string, mixed> */
    private function base(array $extra = []): array
    {
        return array_merge(['id' => 'site', 'migrations_path' => '/tmp/m'], $extra);
    }

    public function testDefaultsAreAllowOrderAndMoscow(): void
    {
        $config = new ProjectConfig($this->base(), '/tmp');

        self::assertSame('allow', $config->getOrder());
        self::assertFalse($config->isStrictOrder());
        self::assertSame('Europe/Moscow', $config->getLabelTimezone());
    }

    public function testStrictOrderAndTimezoneFromConfig(): void
    {
        $config = new ProjectConfig($this->base(['order' => 'strict', 'label_timezone' => 'UTC']), '/tmp');

        self::assertTrue($config->isStrictOrder());
        self::assertSame('UTC', $config->getLabelTimezone());
    }

    public function testUnknownOrderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProjectConfig($this->base(['order' => 'whatever']), '/tmp');
    }
}


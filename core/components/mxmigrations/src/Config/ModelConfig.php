<?php

namespace MxMigrations\Config;

final class ModelConfig
{
    private string $name;
    private string $schemaPath;
    private string $modelPath;
    private bool $overlay;

    public function __construct(string $name, string $schemaPath, string $modelPath, bool $overlay)
    {
        $this->name = $name;
        $this->schemaPath = $schemaPath;
        $this->modelPath = $modelPath;
        $this->overlay = $overlay;
    }

    public function getName(): string { return $this->name; }
    public function getSchemaPath(): string { return $this->schemaPath; }
    public function getModelPath(): string { return $this->modelPath; }
    public function usesOverlay(): bool { return $this->overlay; }
}

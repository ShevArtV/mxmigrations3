<?php

/**
 * Запись журнала миграций.
 *
 * Неизменяемый объект. Журнал в основном дописывается; единственная правка записи —
 * смена имени (`JournalInterface::rename`) у призрака переименования, остальные поля
 * применённой миграции не меняются.
 */

namespace MxMigrations\Core;

/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class MigrationRecord
{
    private string $name;
    private string $checksum;
    private string $status;
    private int $appliedAt;
    private string $appliedBy;
    private int $duration;
    private string $output;

    public function __construct(
        string $name,
        string $checksum,
        string $status,
        int $appliedAt,
        string $appliedBy = '',
        int $duration = 0,
        string $output = ''
    ) {
        $this->name = $name;
        $this->checksum = $checksum;
        $this->status = $status;
        $this->appliedAt = $appliedAt;
        $this->appliedBy = $appliedBy;
        $this->duration = $duration;
        $this->output = $output;
    }

    /**
     * @param array<string, mixed> $row Строка из journal-таблицы.
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (string)($row['name'] ?? ''),
            (string)($row['checksum'] ?? ''),
            (string)($row['status'] ?? ''),
            (int)($row['applied_at'] ?? 0),
            (string)($row['applied_by'] ?? ''),
            (int)($row['duration'] ?? 0),
            (string)($row['output'] ?? '')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'checksum' => $this->checksum,
            'status' => $this->status,
            'applied_at' => $this->appliedAt,
            'applied_by' => $this->appliedBy,
            'duration' => $this->duration,
            'output' => $this->output,
        ];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAppliedAt(): int
    {
        return $this->appliedAt;
    }

    public function getAppliedBy(): string
    {
        return $this->appliedBy;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function getOutput(): string
    {
        return $this->output;
    }
}

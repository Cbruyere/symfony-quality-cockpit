<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Infection;

final readonly class InfectionMutant
{
    public function __construct(
        public string $id,
        public string $status,
        public ?string $className,
        public ?string $method,
        public ?string $file,
        public ?int $line,
        public ?string $mutator,
        public ?string $originalSourceCode,
        public ?string $mutatedSourceCode,
        public ?string $diff,
        public ?string $processOutput,
    ) {
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'killed' => 'Killed', 'escaped' => 'Escaped', 'not_covered' => 'Non couvert',
            'error' => 'Erreur', 'timed_out' => 'Timeout', 'skipped' => 'Ignore',
            'ignored' => 'Ignore', default => $this->status,
        };
    }

    public function statusLevel(): string
    {
        return match ($this->status) {
            'killed' => 'success', 'escaped', 'not_covered' => 'danger',
            'timed_out', 'error' => 'warning', default => 'neutral',
        };
    }

    public function location(): string
    {
        return ($this->file ?? 'Fichier inconnu').(null !== $this->line ? ':'.$this->line : '');
    }

    public function changeSummary(): string
    {
        return trim((string) ($this->diff ?? $this->mutatedSourceCode ?? ''));
    }
}


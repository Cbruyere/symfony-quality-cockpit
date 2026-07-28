<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Infection;

final readonly class InfectionReport
{
    /**
     * @param list<InfectionMutant> $mutants
     * @param list<InfectionClassSummary> $classes
     * @param list<InfectionMutatorSummary> $mutators
     */
    public function __construct(
        public bool $available,
        public bool $valid,
        public string $message,
        public string $freshness,
        public ?\DateTimeImmutable $generatedAt,
        public ?string $infectionVersion,
        public InfectionMetrics $metrics,
        public array $mutants,
        public array $classes,
        public array $mutators,
        public ?string $htmlReportPath,
        public ?string $jsonReportPath,
        public ?string $summaryJsonPath,
        public ?string $textReportPath,
    ) {
    }

    public static function unavailable(string $message = 'Rapport indisponible'): self
    {
        return new self(false, false, $message, 'indisponible', null, null, InfectionMetrics::empty(), [], [], [], null, null, null, null);
    }

    public function statusLabel(): string
    {
        if (!$this->available || !$this->valid) {
            return 'Rapport indisponible';
        }

        return match (true) {
            null === $this->metrics->coveredCodeMsi => 'Aucun mutant',
            $this->metrics->coveredCodeMsi >= 90 => 'Excellent',
            $this->metrics->coveredCodeMsi >= 80 => 'Bon',
            $this->metrics->coveredCodeMsi >= 60 => 'A renforcer',
            default => 'Critique',
        };
    }

    public function statusLevel(): string
    {
        return match ($this->statusLabel()) {
            'Excellent' => 'success', 'Bon' => 'info', 'A renforcer' => 'warning', 'Critique' => 'danger', default => 'neutral',
        };
    }
}


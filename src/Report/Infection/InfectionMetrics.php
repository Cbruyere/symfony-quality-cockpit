<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Infection;

final readonly class InfectionMetrics
{
    public function __construct(
        public int $total,
        public int $killed,
        public int $escaped,
        public int $notCovered,
        public int $errors,
        public int $timeouts,
        public int $skipped,
        public int $ignored,
        public ?float $msi,
        public ?float $mutationCodeCoverage,
        public ?float $coveredCodeMsi,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, 0, null, null, null);
    }

    public function percentage(?float $value): string
    {
        return null === $value ? 'n/a' : number_format($value, 2, ',', ' ').'%';
    }
}


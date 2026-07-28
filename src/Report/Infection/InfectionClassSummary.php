<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Infection;

final readonly class InfectionClassSummary
{
    /** @param list<string> $survivingMutators */
    public function __construct(
        public string $className,
        public int $total,
        public int $killed,
        public int $escaped,
        public int $notCovered,
        public ?float $coveredCodeMsi,
        public array $survivingMutators,
    ) {
    }
}


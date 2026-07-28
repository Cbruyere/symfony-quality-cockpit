<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Infection;

final readonly class InfectionMutatorSummary
{
    public function __construct(
        public string $mutator,
        public int $total,
        public int $killed,
        public int $escaped,
        public int $errors,
        public int $timeouts,
        public ?float $efficiency,
    ) {
    }
}


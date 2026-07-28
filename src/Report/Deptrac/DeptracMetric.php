<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Deptrac;

final readonly class DeptracMetric
{
    public function __construct(
        public string $label,
        public int|float $value,
        public string $formattedValue,
        public string $level,
    ) {
    }
}


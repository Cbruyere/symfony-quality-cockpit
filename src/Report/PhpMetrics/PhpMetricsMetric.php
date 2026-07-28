<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\PhpMetrics;

final readonly class PhpMetricsMetric
{
    public function __construct(
        public string $label,
        public float|null $value,
        public string $formattedValue,
        public string $level,
    ) {
    }
}


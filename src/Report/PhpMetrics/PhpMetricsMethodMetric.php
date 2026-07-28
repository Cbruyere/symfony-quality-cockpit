<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\PhpMetrics;

final readonly class PhpMetricsMethodMetric
{
    public function __construct(
        public string $name,
        public string $role,
        public string $visibility,
        public float|null $cyclomaticComplexity,
        public string $level,
    ) {
    }
}


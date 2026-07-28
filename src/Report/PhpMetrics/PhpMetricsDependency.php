<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\PhpMetrics;

final readonly class PhpMetricsDependency
{
    public function __construct(
        public string $source,
        public string $target,
        public string $type,
    ) {
    }
}


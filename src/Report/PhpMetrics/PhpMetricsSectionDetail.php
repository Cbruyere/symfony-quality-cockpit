<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\PhpMetrics;

final readonly class PhpMetricsSectionDetail
{
    /**
     * @param list<PhpMetricsClassMetric> $classes
     * @param list<string>                $metricKeys
     */
    public function __construct(
        public string $title,
        public string $eyebrow,
        public string $description,
        public string $rawReportPath,
        public array $classes,
        public array $metricKeys,
    ) {
    }
}


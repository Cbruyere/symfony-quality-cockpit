<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\PhpMetrics;

final readonly class PhpMetricsReport
{
    /**
     * @param list<PhpMetricsClassMetric> $classes
     * @param list<PhpMetricsClassMetric> $criticalClasses
     * @param list<PhpMetricsClassMetric> $topComplexClasses
     * @param list<PhpMetricsDependency>  $dependencies
     */
    public function __construct(
        public bool $available,
        public \DateTimeImmutable|null $generatedAt,
        public string|null $htmlIndexPath,
        public int $totalClasses,
        public int $criticalClassesCount,
        public PhpMetricsMetric $averageMaintainabilityIndex,
        public PhpMetricsMetric $averageCyclomaticComplexity,
        public PhpMetricsMetric $averageCoupling,
        public PhpMetricsMetric $averageLackOfCohesion,
        public PhpMetricsMetric $averageHalsteadVolume,
        public array $classes,
        public array $criticalClasses,
        public array $topComplexClasses,
        public array $dependencies,
    ) {
    }

    public static function unavailable(): self
    {
        $metric = new PhpMetricsMetric('n/a', null, 'n/a', 'neutral');

        return new self(
            available: false,
            generatedAt: null,
            htmlIndexPath: null,
            totalClasses: 0,
            criticalClassesCount: 0,
            averageMaintainabilityIndex: $metric,
            averageCyclomaticComplexity: $metric,
            averageCoupling: $metric,
            averageLackOfCohesion: $metric,
            averageHalsteadVolume: $metric,
            classes: [],
            criticalClasses: [],
            topComplexClasses: [],
            dependencies: [],
        );
    }
}


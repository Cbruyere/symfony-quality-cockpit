<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\PhpMetrics;

final readonly class PhpMetricsClassMetric
{
    /**
     * @param list<PhpMetricsMethodMetric> $methods
     * @param list<PhpMetricsDependency>   $dependencies
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $shortName,
        public string $namespace,
        public PhpMetricsMetric $maintainabilityIndex,
        public PhpMetricsMetric $cyclomaticComplexity,
        public PhpMetricsMetric $maxMethodComplexity,
        public PhpMetricsMetric $coupling,
        public PhpMetricsMetric $afferentCoupling,
        public PhpMetricsMetric $efferentCoupling,
        public PhpMetricsMetric $lackOfCohesion,
        public PhpMetricsMetric $halsteadVolume,
        public int $methodsCount,
        public int $methodsIncludingGettersSettersCount,
        public int $publicMethodsCount,
        public int $privateMethodsCount,
        public int $getterMethodsCount,
        public int $setterMethodsCount,
        public int $weightedMethodCount,
        public bool $critical,
        public string $htmlReportPath,
        public array $methods,
        public array $dependencies,
    ) {
    }
}


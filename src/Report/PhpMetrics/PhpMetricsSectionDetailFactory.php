<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\PhpMetrics;

final class PhpMetricsSectionDetailFactory
{
    public function create(string $section, PhpMetricsReport $report): ?PhpMetricsSectionDetail
    {
        $classes = $report->classes;

        return match ($section) {
            'maintainability' => new PhpMetricsSectionDetail(
                title: 'Maintainability Index',
                eyebrow: 'Maintenabilite',
                description: 'Classes triees par maintenabilite croissante.',
                rawReportPath: 'index.html',
                classes: $this->sortClasses($classes, 'maintainability', ascending: true),
                metricKeys: ['maintainability', 'complexity', 'coupling', 'cohesion'],
            ),
            'complexity' => new PhpMetricsSectionDetail(
                title: 'Complexite cyclomatique',
                eyebrow: 'Complexite',
                description: 'Classes triees par complexite cyclomatique decroissante.',
                rawReportPath: 'complexity.html',
                classes: $this->sortClasses($classes, 'complexity', ascending: false),
                metricKeys: ['complexity', 'maxMethodComplexity', 'maintainability', 'volume'],
            ),
            'coupling' => new PhpMetricsSectionDetail(
                title: 'Couplage',
                eyebrow: 'Architecture',
                description: 'Classes triees par couplage total decroissant.',
                rawReportPath: 'coupling.html',
                classes: $this->sortClasses($classes, 'coupling', ascending: false),
                metricKeys: ['coupling', 'afferentCoupling', 'efferentCoupling', 'maintainability'],
            ),
            'cohesion' => new PhpMetricsSectionDetail(
                title: 'Lack of Cohesion',
                eyebrow: 'Cohesion',
                description: 'Classes triees par manque de cohesion decroissant.',
                rawReportPath: 'oop.html',
                classes: $this->sortClasses($classes, 'cohesion', ascending: false),
                metricKeys: ['cohesion', 'complexity', 'coupling', 'maintainability'],
            ),
            'volume' => new PhpMetricsSectionDetail(
                title: 'Halstead Volume',
                eyebrow: 'Volume',
                description: 'Classes triees par volume Halstead decroissant.',
                rawReportPath: 'loc.html',
                classes: $this->sortClasses($classes, 'volume', ascending: false),
                metricKeys: ['volume', 'complexity', 'maintainability', 'cohesion'],
            ),
            'classes' => new PhpMetricsSectionDetail(
                title: 'Classes analysees',
                eyebrow: 'Inventaire',
                description: 'Toutes les classes presentes dans le rapport PhpMetrics.',
                rawReportPath: 'all.html',
                classes: $classes,
                metricKeys: ['maintainability', 'complexity', 'coupling', 'cohesion'],
            ),
            'violations' => new PhpMetricsSectionDetail(
                title: 'Classes critiques',
                eyebrow: 'Attention',
                description: 'Classes marquees critiques par les seuils du tableau de bord.',
                rawReportPath: 'violations.html',
                classes: $report->criticalClasses,
                metricKeys: ['maintainability', 'complexity', 'coupling', 'cohesion'],
            ),
            default => null,
        };
    }

    /**
     * @param list<PhpMetricsClassMetric> $classes
     *
     * @return list<PhpMetricsClassMetric>
     */
    private function sortClasses(array $classes, string $metric, bool $ascending): array
    {
        usort(
            $classes,
            function (PhpMetricsClassMetric $first, PhpMetricsClassMetric $second) use ($metric, $ascending): int {
                $left = $this->readMetricValue($first, $metric);
                $right = $this->readMetricValue($second, $metric);
                $comparison = $left <=> $right ?: $first->name <=> $second->name;

                return $ascending ? $comparison : -$comparison;
            },
        );

        return $classes;
    }

    private function readMetricValue(PhpMetricsClassMetric $classMetric, string $metric): float
    {
        return match ($metric) {
            'maintainability' => $classMetric->maintainabilityIndex->value ?? 0.0,
            'complexity' => $classMetric->cyclomaticComplexity->value ?? 0.0,
            'maxMethodComplexity' => $classMetric->maxMethodComplexity->value ?? 0.0,
            'coupling' => $classMetric->coupling->value ?? 0.0,
            'afferentCoupling' => $classMetric->afferentCoupling->value ?? 0.0,
            'efferentCoupling' => $classMetric->efferentCoupling->value ?? 0.0,
            'cohesion' => $classMetric->lackOfCohesion->value ?? 0.0,
            'volume' => $classMetric->halsteadVolume->value ?? 0.0,
            default => 0.0,
        };
    }
}


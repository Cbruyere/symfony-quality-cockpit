<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Tests\Unit\Report\PhpMetrics;

use Chrisdev\QualityCockpit\Report\PhpMetrics\PhpMetricsClassMetric;
use Chrisdev\QualityCockpit\Report\PhpMetrics\PhpMetricsMetric;
use Chrisdev\QualityCockpit\Report\PhpMetrics\PhpMetricsReport;
use Chrisdev\QualityCockpit\Report\PhpMetrics\PhpMetricsSectionDetailFactory;
use PHPUnit\Framework\TestCase;

final class PhpMetricsSectionDetailFactoryTest extends TestCase
{
    public function testCreateReturnsInternalViolationDetail(): void
    {
        $criticalClass = $this->createClassMetric('App\\CriticalClass', maintainability: 40, complexity: 24, critical: true);
        $healthyClass = $this->createClassMetric('App\\HealthyClass', maintainability: 95, complexity: 3, critical: false);
        $factory = new PhpMetricsSectionDetailFactory();

        $detail = $factory->create('violations', $this->createReport([$criticalClass, $healthyClass], [$criticalClass]));

        self::assertNotNull($detail);
        self::assertSame('Classes critiques', $detail->title);
        self::assertSame('violations.html', $detail->rawReportPath);
        self::assertSame([$criticalClass], $detail->classes);
    }

    public function testCreateSortsComplexityDetailByDescendingComplexity(): void
    {
        $firstClass = $this->createClassMetric('App\\SimpleClass', maintainability: 95, complexity: 2, critical: false);
        $secondClass = $this->createClassMetric('App\\ComplexClass', maintainability: 70, complexity: 18, critical: true);
        $factory = new PhpMetricsSectionDetailFactory();

        $detail = $factory->create('complexity', $this->createReport([$firstClass, $secondClass], [$secondClass]));

        self::assertNotNull($detail);
        self::assertSame('ComplexClass', $detail->classes[0]->shortName);
        self::assertSame(['complexity', 'maxMethodComplexity', 'maintainability', 'volume'], $detail->metricKeys);
    }

    /**
     * @param list<PhpMetricsClassMetric> $classes
     * @param list<PhpMetricsClassMetric> $criticalClasses
     */
    private function createReport(array $classes, array $criticalClasses): PhpMetricsReport
    {
        $metric = new PhpMetricsMetric('n/a', null, 'n/a', 'neutral');

        return new PhpMetricsReport(
            available: true,
            generatedAt: null,
            htmlIndexPath: 'index.html',
            totalClasses: count($classes),
            criticalClassesCount: count($criticalClasses),
            averageMaintainabilityIndex: $metric,
            averageCyclomaticComplexity: $metric,
            averageCoupling: $metric,
            averageLackOfCohesion: $metric,
            averageHalsteadVolume: $metric,
            classes: $classes,
            criticalClasses: $criticalClasses,
            topComplexClasses: [],
            dependencies: [],
        );
    }

    private function createClassMetric(string $className, float $maintainability, float $complexity, bool $critical): PhpMetricsClassMetric
    {
        $shortName = substr($className, strrpos($className, '\\') + 1);

        return new PhpMetricsClassMetric(
            id: base64_encode($className),
            name: $className,
            shortName: $shortName,
            namespace: 'App',
            maintainabilityIndex: new PhpMetricsMetric('Maintainability Index', $maintainability, (string) $maintainability, 'success'),
            cyclomaticComplexity: new PhpMetricsMetric('Cyclomatic Complexity', $complexity, (string) $complexity, 'warning'),
            maxMethodComplexity: new PhpMetricsMetric('Max Method Complexity', $complexity, (string) $complexity, 'warning'),
            coupling: new PhpMetricsMetric('Coupling', 2, '2', 'success'),
            afferentCoupling: new PhpMetricsMetric('Afferent Coupling', 1, '1', 'success'),
            efferentCoupling: new PhpMetricsMetric('Efferent Coupling', 1, '1', 'success'),
            lackOfCohesion: new PhpMetricsMetric('LCOM', 1, '1', 'success'),
            halsteadVolume: new PhpMetricsMetric('Halstead Volume', 100, '100', 'success'),
            methodsCount: 1,
            methodsIncludingGettersSettersCount: 1,
            publicMethodsCount: 1,
            privateMethodsCount: 0,
            getterMethodsCount: 0,
            setterMethodsCount: 0,
            weightedMethodCount: 1,
            critical: $critical,
            htmlReportPath: 'index.html',
            methods: [],
            dependencies: [],
        );
    }
}


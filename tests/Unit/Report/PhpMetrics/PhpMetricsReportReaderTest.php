<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Tests\Unit\Report\PhpMetrics;

use Chrisdev\QualityCockpit\Report\PhpMetrics\PhpMetricsReportReader;
use PHPUnit\Framework\TestCase;

final class PhpMetricsReportReaderTest extends TestCase
{
    public function testReadReturnsUnavailableReportWhenNoPhpMetricsArtifactExists(): void
    {
        $directory = $this->createReportDirectory();
        $reader = new PhpMetricsReportReader();

        $report = $reader->read($directory);

        self::assertFalse($report->available);
        self::assertSame(0, $report->totalClasses);
        self::assertSame([], $report->topComplexClasses);
    }

    public function testReadParsesJsonReportAndAggregatesCodeQualityMetrics(): void
    {
        $directory = $this->createReportDirectory();
        file_put_contents($directory . '/report.json', json_encode([
            'classes' => [
                [
                    'name' => 'App\\UI\\Http\\Controller\\HealthyController',
                    'nbMethods' => 2,
                    'ccn' => 4,
                    'ccnMethodMax' => 3,
                    'mi' => 92.4,
                    'afferentCoupling' => 1,
                    'efferentCoupling' => 2,
                    'lcom' => 1,
                    'volume' => 420.5,
                    'methods' => [
                        ['name' => 'index', 'ccn' => 1],
                        ['name' => 'detail', 'ccn' => 3],
                    ],
                    'externals' => ['Symfony\\Component\\HttpFoundation\\Response'],
                ],
                [
                    'name' => 'App\\UI\\Http\\Controller\\RiskyController',
                    'nbMethods' => 4,
                    'ccn' => 31,
                    'ccnMethodMax' => 18,
                    'mi' => 54.2,
                    'afferentCoupling' => 4,
                    'efferentCoupling' => 9,
                    'lcom' => 5,
                    'volume' => 2450,
                    'methods' => [
                        ['name' => 'export', 'role' => null, 'public' => true, 'private' => false, 'ccn' => 18],
                        ['name' => 'index', 'ccn' => 2],
                    ],
                ],
                [
                    'name' => 'App\\Contract\\DemoInterface',
                    'interface' => true,
                    'ccn' => 1,
                    'mi' => 171,
                ],
            ],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/index.html', '<html></html>');

        $reader = new PhpMetricsReportReader();

        $report = $reader->read($directory);

        self::assertTrue($report->available);
        self::assertSame(2, $report->totalClasses);
        self::assertSame(1, $report->criticalClassesCount);
        self::assertSame('73.3', $report->averageMaintainabilityIndex->formattedValue);
        self::assertSame('17.5', $report->averageCyclomaticComplexity->formattedValue);
        self::assertSame('8', $report->averageCoupling->formattedValue);
        self::assertSame('RiskyController', $report->topComplexClasses[0]->shortName);
        self::assertSame('danger', $report->topComplexClasses[0]->cyclomaticComplexity->level);
        self::assertSame('export', $report->topComplexClasses[0]->methods[0]->name);
        self::assertSame('standard', $report->topComplexClasses[0]->methods[0]->role);
        self::assertSame('public', $report->topComplexClasses[0]->methods[0]->visibility);
        self::assertSame(1, count($report->dependencies));
    }

    public function testReadClassDetailFindsClassByStableId(): void
    {
        $directory = $this->createReportDirectory();
        file_put_contents($directory . '/report.json', json_encode([
            [
                'name' => 'App\\UI\\Http\\PhpMetrics\\PhpMetricsReportReader',
                'ccn' => 12,
                'mi' => 70,
                'afferentCoupling' => 2,
                'efferentCoupling' => 3,
                'lcom' => 2,
                'volume' => 900,
            ],
        ], \JSON_THROW_ON_ERROR));

        $reader = new PhpMetricsReportReader();
        $report = $reader->read($directory);

        $detail = $reader->readClassDetail($directory, $report->classes[0]->id);

        self::assertNotNull($detail);
        self::assertSame('PhpMetricsReportReader', $detail->shortName);
        self::assertSame('warning', $detail->cyclomaticComplexity->level);
    }

    public function testReadParsesAssociativePhpMetricsJsonReport(): void
    {
        $directory = $this->createReportDirectory();
        file_put_contents($directory . '/report.json', json_encode([
            'App\\Demo\\GeneratedClass' => [
                'name' => 'App\\Demo\\GeneratedClass',
                '_type' => 'Hal\\Metric\\ClassMetric',
                'interface' => false,
                'ccn' => 3,
                'ccnMethodMax' => 2,
                'mi' => 101.5,
                'afferentCoupling' => 1,
                'efferentCoupling' => 1,
                'lcom' => 0,
                'volume' => 12,
            ],
            'App\\Demo\\' => [
                'name' => 'App\\Demo\\',
                '_type' => 'Hal\\Metric\\PackageMetric',
                'classes' => ['App\\Demo\\GeneratedClass'],
            ],
        ], \JSON_THROW_ON_ERROR));

        $reader = new PhpMetricsReportReader();

        $report = $reader->read($directory);

        self::assertTrue($report->available);
        self::assertSame(1, $report->totalClasses);
        self::assertSame('GeneratedClass', $report->classes[0]->shortName);
    }

    public function testReadParsesClassesJsFallbackFromHtmlReport(): void
    {
        $directory = $this->createReportDirectory();
        mkdir($directory . '/js');
        file_put_contents($directory . '/js/classes.js', <<<'JAVASCRIPT'
var classes = [
    {"name":"App\\Demo\\FallbackClass","ccn":2,"mi":99,"afferentCoupling":0,"efferentCoupling":1,"lcom":1,"volume":100}
];
JAVASCRIPT);

        $reader = new PhpMetricsReportReader();

        $report = $reader->read($directory);

        self::assertTrue($report->available);
        self::assertSame('FallbackClass', $report->classes[0]->shortName);
    }

    private function createReportDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/phpmetrics-report-' . bin2hex(random_bytes(8));
        mkdir($directory, recursive: true);

        return $directory;
    }
}


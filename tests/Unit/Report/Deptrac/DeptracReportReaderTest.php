<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Tests\Unit\Report\Deptrac;

use Chrisdev\QualityCockpit\Report\Deptrac\DeptracReportReader;
use PHPUnit\Framework\TestCase;

final class DeptracReportReaderTest extends TestCase
{
    public function testReadReturnsCleanReportWhenNoViolationExists(): void
    {
        $directory = $this->createReportDirectory();
        file_put_contents($directory . '/report.json', json_encode([
            'summary' => ['score' => 100],
            'layers' => ['Domain', 'Application', 'Infrastructure', 'UI'],
            'ruleset' => [
                'UI' => ['Application'],
                'Application' => ['Domain'],
                'Infrastructure' => ['Application', 'Domain'],
            ],
            'violations' => [],
        ], \JSON_THROW_ON_ERROR));

        $report = (new DeptracReportReader())->read($directory);

        self::assertTrue($report->available);
        self::assertTrue($report->valid);
        self::assertSame(0, count($report->violations));
        self::assertSame('success', $report->violationsMetric->level);
        self::assertSame(4, $report->layersMetric->value);
        self::assertSame(4, $report->rulesMetric->value);
        self::assertSame('100%', $report->scoreMetric->formattedValue);
    }

    public function testReadParsesOneViolation(): void
    {
        $directory = $this->createReportDirectory();
        file_put_contents($directory . '/report.json', json_encode([
            'summary' => ['score' => 90],
            'violations' => [
                [
                    'sourceClass' => 'App\\UI\\Http\\Controller\\DemoController',
                    'targetClass' => 'App\\Domain\\RepairOrder\\RepairOrder',
                    'rule' => 'UI must not depend on Domain',
                    'sourceLayer' => 'UI',
                    'targetLayer' => 'Domain',
                    'severity' => 'error',
                    'file' => 'src/UI/Http/Controller/DemoController.php',
                    'line' => 42,
                    'codeExcerpt' => 'new RepairOrder()',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $reader = new DeptracReportReader();
        $report = $reader->read($directory);
        $violation = $report->violations[0];

        self::assertSame(1, count($report->violations));
        self::assertSame('warning', $report->violationsMetric->level);
        self::assertSame('App\\UI\\Http\\Controller\\DemoController', $violation->sourceClass);
        self::assertSame('App\\Domain\\RepairOrder\\RepairOrder', $violation->targetClass);
        self::assertSame('UI', $violation->sourceLayer);
        self::assertSame('Domain', $violation->targetLayer);
        self::assertSame('danger', $violation->level);
        self::assertSame(42, $violation->line);

        $detail = $reader->readViolationDetail($directory, $violation->id);

        self::assertNotNull($detail);
        self::assertSame($violation->sourceClass, $detail->sourceClass);
        self::assertSame($violation->targetClass, $detail->targetClass);
    }

    public function testReadParsesMultipleViolationsAndUniqueForbiddenDependencies(): void
    {
        $directory = $this->createReportDirectory();
        file_put_contents($directory . '/report.json', json_encode([
            'violations' => [
                [
                    'source' => ['class' => 'App\\UI\\A'],
                    'target' => ['class' => 'App\\Domain\\A'],
                    'source_layer' => 'UI',
                    'target_layer' => 'Domain',
                    'message' => 'UI depends on Domain',
                    'severity' => 'warning',
                ],
                [
                    'source' => ['class' => 'App\\UI\\A'],
                    'target' => ['class' => 'App\\Domain\\A'],
                    'source_layer' => 'UI',
                    'target_layer' => 'Domain',
                    'message' => 'Duplicate dependency',
                    'severity' => 'warning',
                ],
                [
                    'source' => ['class' => 'App\\Application\\A'],
                    'target' => ['class' => 'App\\Infrastructure\\A'],
                    'source_layer' => 'Application',
                    'target_layer' => 'Infrastructure',
                    'message' => 'Application depends on Infrastructure',
                    'severity' => 'error',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $report = (new DeptracReportReader())->read($directory);

        self::assertSame(3, count($report->violations));
        self::assertSame(2, $report->forbiddenDependenciesCount);
        self::assertSame(['Application', 'Domain', 'Infrastructure', 'UI'], $report->layers);
        self::assertSame('warning', $report->violations[0]->level);
        self::assertSame('danger', $report->violations[2]->level);
    }

    public function testReadParsesNativeDeptracReportAndCopiedConfiguration(): void
    {
        $directory = $this->createReportDirectory();
        file_put_contents($directory . '/report.json', json_encode([
            'Report' => [
                'Violations' => 0,
                'Skipped violations' => 0,
                'Uncovered' => 38,
                'Allowed' => 0,
                'Warnings' => 0,
                'Errors' => 0,
            ],
            'files' => [],
        ], \JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/deptrac.yaml', <<<'YAML'
deptrac:
    layers:
        -
            name: Controller
        -
            name: Service
        -
            name: Repository
    ruleset:
        Controller:
            - Service
        Service:
            - Repository
        Repository: []
YAML);

        $report = (new DeptracReportReader())->read($directory);

        self::assertTrue($report->available);
        self::assertSame(0, $report->violationsMetric->value);
        self::assertSame(3, $report->layersMetric->value);
        self::assertSame(2, $report->rulesMetric->value);
        self::assertSame('100%', $report->scoreMetric->formattedValue);
    }

    public function testReadReturnsUnavailableReportWhenReportIsInvalid(): void
    {
        $directory = $this->createReportDirectory();
        file_put_contents($directory . '/report.json', '{invalid json');

        $report = (new DeptracReportReader())->read($directory);

        self::assertFalse($report->available);
        self::assertFalse($report->valid);
        self::assertSame([], $report->violations);
    }

    private function createReportDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/deptrac-report-' . bin2hex(random_bytes(8));
        mkdir($directory, recursive: true);

        return $directory;
    }
}


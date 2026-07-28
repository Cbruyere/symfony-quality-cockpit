<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Tests\Unit\Health;

use Chrisdev\QualityCockpit\Report\ComposerAudit\ComposerAuditReport;
use Chrisdev\QualityCockpit\Report\Deptrac\DeptracReport;
use Chrisdev\QualityCockpit\Report\Infection\InfectionReport;
use Chrisdev\QualityCockpit\Report\Infection\InfectionMetrics;
use Chrisdev\QualityCockpit\Report\PhpMetrics\PhpMetricsReport;
use Chrisdev\QualityCockpit\Health\QualityHealthEvaluator;
use Chrisdev\QualityCockpit\Health\QualityHealthSummaryBuilder;
use Chrisdev\QualityCockpit\Health\QualitySeverity;
use Chrisdev\QualityCockpit\Health\QualityThresholds;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class QualityHealthEvaluatorTest extends TestCase
{
    public function testReportsAbsentProduceUnavailableHealth(): void
    {
        $report = $this->evaluator()->evaluate([], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), InfectionReport::unavailable());

        self::assertSame(QualitySeverity::UNAVAILABLE, $report->severity);
        self::assertSame(0, $report->actionCount());
        self::assertSame([], $report->allActions);
    }

    public function testCoverageAtNinetyIsHealthyAndBelowFiftyIsCritical(): void
    {
        $healthy = $this->evaluator()->evaluate(['summary' => ['lines' => ['percent' => '90,00%']]], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), InfectionReport::unavailable());
        $critical = $this->evaluator()->evaluate(['summary' => ['lines' => ['percent' => '49,99%']]], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), InfectionReport::unavailable());

        self::assertSame(QualitySeverity::HEALTHY, $healthy->metrics[0]->severity);
        self::assertSame(QualitySeverity::CRITICAL, $critical->metrics[0]->severity);
    }

    #[DataProvider('coverageBoundaryProvider')]
    public function testCoverageBoundariesAreEvaluatedInclusively(float $coverage, QualitySeverity $severity): void
    {
        $report = $this->evaluator()->evaluate(['summary' => ['lines' => ['percent' => $coverage]]], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), InfectionReport::unavailable());

        self::assertSame($severity, $report->metrics[0]->severity);
    }

    /** @return iterable<string, array{float, QualitySeverity}> */
    public static function coverageBoundaryProvider(): iterable
    {
        yield 'excellent boundary' => [90.0, QualitySeverity::HEALTHY];
        yield 'good boundary' => [80.0, QualitySeverity::GOOD];
        yield 'warning boundary' => [65.0, QualitySeverity::WARNING];
        yield 'degraded boundary' => [50.0, QualitySeverity::DEGRADED];
        yield 'critical below degraded' => [49.99, QualitySeverity::CRITICAL];
    }

    #[DataProvider('invalidCoverageProvider')]
    public function testInvalidCoverageIsUnavailable(mixed $coverage): void
    {
        $report = $this->evaluator()->evaluate(['summary' => ['lines' => ['percent' => $coverage]]], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), InfectionReport::unavailable());

        self::assertSame(QualitySeverity::UNAVAILABLE, $report->metrics[0]->severity);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCoverageProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'above one hundred' => [100.1];
        yield 'invalid string' => ['coverage inconnue'];
    }

    #[DataProvider('validCoverageProvider')]
    public function testNumericAndLocalizedCoverageIsAccepted(mixed $coverage): void
    {
        $report = $this->evaluator()->evaluate(['summary' => ['lines' => ['percent' => $coverage]]], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), InfectionReport::unavailable());

        self::assertNotSame(QualitySeverity::UNAVAILABLE, $report->metrics[0]->severity);
    }

    /** @return iterable<string, array{mixed}> */
    public static function validCoverageProvider(): iterable
    {
        yield 'integer' => [54];
        yield 'float' => [54.02];
        yield 'string' => ['54.02'];
        yield 'localized string' => [' 54,02 % '];
    }

    public function testInfectionErrorsAndTimeoutsMakeHealthCritical(): void
    {
        $report = $this->evaluator()->evaluate([], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), $this->infectionReport(95.92, 0, 1, 1));

        self::assertSame(QualitySeverity::CRITICAL, $report->severity);
        self::assertSame(QualitySeverity::CRITICAL, $report->metrics[1]->severity);
        self::assertStringContainsString('erreur', $report->allActions[0]->description);
        self::assertStringContainsString('timeout', $report->allActions[0]->description);
    }

    public function testHighInfectionScoreWithEscapedMutantsRemainsGoodButCreatesImprovement(): void
    {
        $report = $this->evaluator()->evaluate([], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), $this->infectionReport(95.92, 2, 0, 0));

        self::assertSame(QualitySeverity::GOOD, $report->severity);
        self::assertSame(QualitySeverity::GOOD, $report->metrics[1]->severity);
        self::assertSame('2 mutants échappés', $report->allActions[0]->description);
        self::assertSame(1, $report->improvementCount());
    }

    #[DataProvider('infectionBoundaryProvider')]
    public function testInfectionBoundariesKeepTechnicalSeverityAndEditorialQualifier(float $msi, int $escaped, QualitySeverity $severity, string $qualifier): void
    {
        $report = $this->evaluator()->evaluate([], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), $this->infectionReport($msi, $escaped, 0, 0));

        self::assertSame($severity, $report->metrics[1]->severity);
        self::assertSame($qualifier, $report->metrics[1]->qualifier);
    }

    /** @return iterable<string, array{float, int, QualitySeverity, string}> */
    public static function infectionBoundaryProvider(): iterable
    {
        yield 'excellent without survivors' => [100.0, 0, QualitySeverity::HEALTHY, 'Excellent'];
        yield 'very good with survivors' => [95.0, 1, QualitySeverity::GOOD, 'Très bon'];
        yield 'good' => [85.0, 0, QualitySeverity::GOOD, 'Bon'];
        yield 'warning' => [70.0, 0, QualitySeverity::WARNING, 'À renforcer'];
        yield 'degraded' => [60.0, 0, QualitySeverity::DEGRADED, 'Dégradé'];
    }

    public function testHealthyAndGoodMetricsProduceGoodGlobalHealth(): void
    {
        $report = $this->evaluator()->evaluate(['summary' => ['lines' => ['percent' => 90]]], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), $this->infectionReport(90, 0, 0, 0));

        self::assertSame(QualitySeverity::GOOD, $report->severity);
    }

    #[DataProvider('escapedMutantsProvider')]
    public function testEscapedMutantsArePluralized(int $count, string $expected): void
    {
        $report = $this->evaluator()->evaluate([], PhpMetricsReport::unavailable(), DeptracReport::unavailable(), ComposerAuditReport::unavailable(), $this->infectionReport(95, $count, 0, 0));

        self::assertSame($expected, $report->allActions[0]->description);
    }

    /** @return iterable<string, array{int, string}> */
    public static function escapedMutantsProvider(): iterable
    {
        yield 'one' => [1, '1 mutant échappé'];
        yield 'many' => [2, '2 mutants échappés'];
    }

    public function testThresholdsRejectInvalidOrdering(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new QualityThresholds([
            'phpunit' => ['excellent' => 90, 'good' => 95, 'warning' => 65, 'degraded' => 50],
            'infection' => ['excellent' => 100, 'very_good' => 95, 'good' => 85, 'warning' => 70, 'degraded' => 60],
            'phpmetrics' => ['warning_classes' => 1, 'degraded_classes' => 5, 'critical_classes' => 15],
        ]);
    }

    private function evaluator(): QualityHealthEvaluator
    {
        return new QualityHealthEvaluator(
            new QualityThresholds([
                'phpunit' => ['excellent' => 90, 'good' => 80, 'warning' => 65, 'degraded' => 50],
                'infection' => ['excellent' => 100, 'very_good' => 95, 'good' => 85, 'warning' => 70, 'degraded' => 60],
                'phpmetrics' => ['warning_classes' => 1, 'degraded_classes' => 5, 'critical_classes' => 15],
            ]),
            new QualityHealthSummaryBuilder(),
        );
    }

    private function infectionReport(float $coveredCodeMsi, int $escaped, int $errors, int $timeouts): InfectionReport
    {
        return new InfectionReport(
            available: true,
            valid: true,
            message: '',
            freshness: 'recent',
            generatedAt: null,
            infectionVersion: null,
            metrics: new InfectionMetrics(49, 47, $escaped, 0, $errors, $timeouts, 0, 0, $coveredCodeMsi, 100.0, $coveredCodeMsi),
            mutants: [],
            classes: [],
            mutators: [],
            htmlReportPath: null,
            jsonReportPath: null,
            summaryJsonPath: null,
            textReportPath: null,
        );
    }
}


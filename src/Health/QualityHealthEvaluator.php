<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Health;

use Chrisdev\QualityCockpit\Report\ComposerAudit\ComposerAuditReport;
use Chrisdev\QualityCockpit\Report\Deptrac\DeptracReport;
use Chrisdev\QualityCockpit\Report\Infection\InfectionReport;
use Chrisdev\QualityCockpit\Report\PhpMetrics\PhpMetricsReport;

final readonly class QualityHealthEvaluator
{
    public function __construct(
        private QualityThresholds $thresholds,
        private QualityHealthSummaryBuilder $summaryBuilder,
    ) {
    }

    /** @param array<string, mixed> $coverage */
    public function evaluate(
        array $coverage,
        PhpMetricsReport $phpMetrics,
        DeptracReport $deptrac,
        ComposerAuditReport $audit,
        InfectionReport $infection,
    ): QualityHealthReport {
        $results = [
            $this->evaluatePhpUnit($coverage),
            $this->evaluateInfection($infection),
            $this->evaluatePhpMetrics($phpMetrics),
            $this->evaluateDeptrac($deptrac),
            $this->evaluateComposerAudit($audit),
        ];
        $metrics = array_map(static fn (QualityEvaluationResult $result): QualityMetricAssessment => $result->assessment, $results);
        $actions = array_merge(...array_map(static fn (QualityEvaluationResult $result): array => $result->actions, $results));
        usort($actions, static fn (QualityActionItem $first, QualityActionItem $second): int => $first->priority <=> $second->priority);

        $severity = $this->evaluateGlobalSeverity($metrics);

        return new QualityHealthReport(
            severity: $severity,
            qualifier: $severity->label(),
            metrics: $metrics,
            actions: array_slice($actions, 0, 3),
            allActions: $actions,
            summary: $this->summaryBuilder->build($severity, $metrics, $actions),
        );
    }

    /** @param array<string, mixed> $coverage */
    private function evaluatePhpUnit(array $coverage): QualityEvaluationResult
    {
        $value = $this->coverageValue($coverage['summary']['lines']['percent'] ?? null);
        if (null === $value) {
            return new QualityEvaluationResult(new QualityMetricAssessment('PHPUnit', 'Couverture PHPUnit', 'n/a', QualitySeverity::UNAVAILABLE, 'Indisponible'), []);
        }

        $severity = $this->evaluatePhpUnitSeverity($value);
        $actions = $severity->rank() >= QualitySeverity::WARNING->rank()
            ? [new QualityActionItem('PHPUnit', $severity, 'Renforcer la couverture PHPUnit', 'La couverture est sous le niveau recommandé.', 6, '#phpunit')]
            : [];

        return new QualityEvaluationResult(
            new QualityMetricAssessment('PHPUnit', 'Couverture PHPUnit', number_format($value, 2, ',', ' ').' %', $severity, $severity->label()),
            $actions,
        );
    }

    private function evaluatePhpUnitSeverity(float $coverage): QualitySeverity
    {
        if ($coverage >= $this->thresholds->get('phpunit', 'excellent')) {
            return QualitySeverity::HEALTHY;
        }
        if ($coverage >= $this->thresholds->get('phpunit', 'good')) {
            return QualitySeverity::GOOD;
        }
        if ($coverage >= $this->thresholds->get('phpunit', 'warning')) {
            return QualitySeverity::WARNING;
        }
        if ($coverage >= $this->thresholds->get('phpunit', 'degraded')) {
            return QualitySeverity::DEGRADED;
        }

        return QualitySeverity::CRITICAL;
    }

    private function evaluateInfection(InfectionReport $report): QualityEvaluationResult
    {
        if (!$report->available || !$report->valid) {
            return new QualityEvaluationResult(new QualityMetricAssessment('Infection', 'Infection', 'n/a', QualitySeverity::UNAVAILABLE, 'Indisponible'), []);
        }

        $metrics = $report->metrics;
        $technicalFailure = $metrics->errors > 0 || $metrics->timeouts > 0;
        $severity = $this->evaluateInfectionSeverity($metrics->coveredCodeMsi, $metrics->escaped, $metrics->errors, $metrics->timeouts);
        $actions = [];

        if ($technicalFailure) {
            $actions[] = new QualityActionItem('Infection', QualitySeverity::CRITICAL, 'Examiner les erreurs Infection', $this->infectionFailureMessage($metrics->errors, $metrics->timeouts), 4, '#infection');
        }
        if ($metrics->escaped > 0) {
            $actions[] = new QualityActionItem('Infection', $severity->rank() >= QualitySeverity::DEGRADED->rank() ? $severity : QualitySeverity::DEGRADED, 'Traiter les mutants échappés', $this->quantity($metrics->escaped, 'mutant échappé'), 5, '?infection_status=escaped#infection');
        }
        if (null === $metrics->coveredCodeMsi) {
            return new QualityEvaluationResult(new QualityMetricAssessment('Infection', 'Infection', 'n/a', $severity, $severity->label()), $actions);
        }

        return new QualityEvaluationResult(
            new QualityMetricAssessment('Infection', 'Infection', $metrics->percentage($metrics->coveredCodeMsi), $severity, $technicalFailure ? QualitySeverity::CRITICAL->label() : $this->infectionQualifier($metrics->coveredCodeMsi, $metrics->escaped), $this->quantity($metrics->escaped, 'mutant restant')),
            $actions,
        );
    }

    private function evaluateInfectionSeverity(?float $coveredCodeMsi, int $escaped, int $errors, int $timeouts): QualitySeverity
    {
        if ($errors > 0 || $timeouts > 0) {
            return QualitySeverity::CRITICAL;
        }
        if (null === $coveredCodeMsi) {
            return QualitySeverity::UNAVAILABLE;
        }
        if (0 === $escaped && $coveredCodeMsi >= $this->thresholds->get('infection', 'excellent')) {
            return QualitySeverity::HEALTHY;
        }
        if ($coveredCodeMsi >= $this->thresholds->get('infection', 'very_good')) {
            return QualitySeverity::GOOD;
        }
        if ($coveredCodeMsi >= $this->thresholds->get('infection', 'good')) {
            return QualitySeverity::GOOD;
        }
        if ($coveredCodeMsi >= $this->thresholds->get('infection', 'warning')) {
            return QualitySeverity::WARNING;
        }
        if ($coveredCodeMsi >= $this->thresholds->get('infection', 'degraded')) {
            return QualitySeverity::DEGRADED;
        }

        return QualitySeverity::CRITICAL;
    }

    private function evaluatePhpMetrics(PhpMetricsReport $report): QualityEvaluationResult
    {
        if (!$report->available) {
            return new QualityEvaluationResult(new QualityMetricAssessment('PhpMetrics', 'Classes critiques', 'n/a', QualitySeverity::UNAVAILABLE, 'Indisponible'), []);
        }

        $count = $report->criticalClassesCount;
        $severity = $this->evaluatePhpMetricsSeverity($count);
        $actions = $count > 0
            ? [new QualityActionItem('PhpMetrics', $severity, 'Examiner les classes signalées', $this->quantity($count, 'classe signalée'), 7, '#phpmetrics-flagged-classes')]
            : [];

        return new QualityEvaluationResult(new QualityMetricAssessment('PhpMetrics', 'Classes critiques', (string) $count, $severity, $severity->label()), $actions);
    }

    private function evaluatePhpMetricsSeverity(int $criticalClasses): QualitySeverity
    {
        if ($criticalClasses < $this->thresholds->get('phpmetrics', 'warning_classes')) {
            return QualitySeverity::HEALTHY;
        }
        if ($criticalClasses < $this->thresholds->get('phpmetrics', 'degraded_classes')) {
            return QualitySeverity::WARNING;
        }
        if ($criticalClasses < $this->thresholds->get('phpmetrics', 'critical_classes')) {
            return QualitySeverity::DEGRADED;
        }

        return QualitySeverity::CRITICAL;
    }

    private function infectionQualifier(float $coveredCodeMsi, int $escaped): string
    {
        if (0 === $escaped && $coveredCodeMsi >= $this->thresholds->get('infection', 'excellent')) {
            return 'Excellent';
        }
        if ($coveredCodeMsi >= $this->thresholds->get('infection', 'very_good')) {
            return 'Très bon';
        }
        if ($coveredCodeMsi >= $this->thresholds->get('infection', 'good')) {
            return 'Bon';
        }
        if ($coveredCodeMsi >= $this->thresholds->get('infection', 'warning')) {
            return 'À renforcer';
        }
        if ($coveredCodeMsi >= $this->thresholds->get('infection', 'degraded')) {
            return 'Dégradé';
        }

        return 'Critique';
    }

    private function evaluateDeptrac(DeptracReport $report): QualityEvaluationResult
    {
        if (!$report->available || !$report->valid) {
            return new QualityEvaluationResult(new QualityMetricAssessment('Deptrac', 'Architecture', 'n/a', QualitySeverity::UNAVAILABLE, 'Indisponible'), []);
        }

        $count = count($report->violations);
        $severity = $count > 0 ? QualitySeverity::CRITICAL : QualitySeverity::HEALTHY;
        $actions = $count > 0
            ? [new QualityActionItem('Deptrac', QualitySeverity::CRITICAL, 'Corriger les violations Deptrac', $this->quantity($count, 'violation d’architecture'), 2, '#deptrac')]
            : [];

        return new QualityEvaluationResult(new QualityMetricAssessment('Deptrac', 'Architecture', $this->quantity($count, 'violation'), $severity, $count > 0 ? $severity->label() : 'Architecture conforme'), $actions);
    }

    private function evaluateComposerAudit(ComposerAuditReport $report): QualityEvaluationResult
    {
        if (!$report->available || !$report->valid) {
            return new QualityEvaluationResult(new QualityMetricAssessment('Composer Audit', 'Sécurité', 'n/a', QualitySeverity::UNAVAILABLE, 'Indisponible'), []);
        }

        $count = count($report->advisories);
        $severity = $count > 0 ? QualitySeverity::CRITICAL : (count($report->abandonedPackages) > 0 ? QualitySeverity::WARNING : QualitySeverity::HEALTHY);
        $actions = $count > 0
            ? [new QualityActionItem('Composer Audit', QualitySeverity::CRITICAL, 'Mettre à jour les dépendances vulnérables', $this->quantity($count, 'vulnérabilité connue'), 1, '#composer-audit')]
            : [];

        return new QualityEvaluationResult(new QualityMetricAssessment('Composer Audit', 'Sécurité', $count > 0 ? 'Vulnérable' : 'Sain', $severity, $count > 0 ? QualitySeverity::CRITICAL->label() : (QualitySeverity::WARNING === $severity ? 'À surveiller' : 'Aucune vulnérabilité connue')), $actions);
    }

    /** @param list<QualityMetricAssessment> $metrics */
    private function evaluateGlobalSeverity(array $metrics): QualitySeverity
    {
        $global = QualitySeverity::UNAVAILABLE;
        foreach ($metrics as $metric) {
            if ($metric->severity->rank() > $global->rank()) {
                $global = $metric->severity;
            }
        }

        return $global;
    }

    private function infectionFailureMessage(int $errors, int $timeouts): string
    {
        if ($errors > 0 && $timeouts > 0) {
            return $this->quantity($errors, 'erreur').' et '.$this->quantity($timeouts, 'timeout').' détectés.';
        }
        if ($errors > 0) {
            return $this->quantity($errors, 'erreur').' détectée.';
        }

        return $this->quantity($timeouts, 'timeout').' détecté.';
    }

    private function quantity(int $count, string $singular): string
    {
        $plural = match ($singular) {
            'mutant échappé' => 'mutants échappés',
            'mutant restant' => 'mutants restants',
            'classe signalée' => 'classes signalées',
            'violation d’architecture' => 'violations d’architecture',
            'violation' => 'violations',
            'vulnérabilité connue' => 'vulnérabilités connues',
            'erreur' => 'erreurs',
            'timeout' => 'timeouts',
            default => $singular.'s',
        };

        return $count.' '.(1 === $count ? $singular : $plural);
    }

    private function coverageValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $this->validCoverage((float) $value);
        }
        if (!is_string($value) || !preg_match('/^\s*([0-9]+(?:[.,][0-9]+)?)\s*%?\s*$/', $value, $matches)) {
            return null;
        }

        return $this->validCoverage((float) str_replace(',', '.', $matches[1]));
    }

    private function validCoverage(float $value): ?float
    {
        return is_finite($value) && $value >= 0.0 && $value <= 100.0 ? $value : null;
    }
}


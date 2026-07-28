<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Health;

final class QualityHealthSummaryBuilder
{
    /**
     * @param list<QualityMetricAssessment> $metrics
     * @param list<QualityActionItem>       $actions
     */
    public function build(QualitySeverity $severity, array $metrics, array $actions): string
    {
        $positive = array_values(array_filter($metrics, static fn (QualityMetricAssessment $metric): bool => in_array($metric->severity, [QualitySeverity::HEALTHY, QualitySeverity::GOOD], true)));
        $parts = ['État global : '.$severity->label().'.'];
        if ([] !== $positive) {
            $parts[] = $positive[0]->tool.' présente un niveau '.$positive[0]->qualifier.'.';
        }
        if ([] !== $actions) {
            $parts[] = count($actions).' point'.(count($actions) > 1 ? 's' : '').' reste'.(count($actions) > 1 ? 'nt' : '').' à traiter.';
        }

        return implode(' ', $parts);
    }
}


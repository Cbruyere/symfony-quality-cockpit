<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Health;

final readonly class QualityHealthReport
{
    /**
     * @param list<QualityMetricAssessment> $metrics
     * @param list<QualityActionItem>       $actions
     * @param list<QualityActionItem>       $allActions
     */
    public function __construct(
        public QualitySeverity $severity,
        public string $qualifier,
        public array $metrics,
        public array $actions,
        public array $allActions,
        public string $summary,
    ) {
    }

    public function actionCount(): int
    {
        return count(array_filter($this->allActions, static fn (QualityActionItem $action): bool => QualitySeverity::CRITICAL === $action->severity));
    }

    public function improvementCount(): int
    {
        return count(array_filter($this->allActions, static fn (QualityActionItem $action): bool => QualitySeverity::CRITICAL !== $action->severity && QualitySeverity::UNAVAILABLE !== $action->severity));
    }

    public function metric(string $tool): ?QualityMetricAssessment
    {
        foreach ($this->metrics as $metric) {
            if ($metric->tool === $tool) {
                return $metric;
            }
        }

        return null;
    }
}


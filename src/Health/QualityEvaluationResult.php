<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Health;

final readonly class QualityEvaluationResult
{
    /** @param list<QualityActionItem> $actions */
    public function __construct(public QualityMetricAssessment $assessment, public array $actions)
    {
    }
}


<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Health;

final readonly class QualityMetricAssessment
{
    public function __construct(
        public string $tool,
        public string $title,
        public string $value,
        public QualitySeverity $severity,
        public string $qualifier,
        public string $message = '',
    ) {
    }
}


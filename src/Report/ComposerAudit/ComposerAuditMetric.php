<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\ComposerAudit;

final readonly class ComposerAuditMetric
{
    public function __construct(
        public string $label,
        public int|string $value,
        public string $formattedValue,
        public string $level,
    ) {
    }
}


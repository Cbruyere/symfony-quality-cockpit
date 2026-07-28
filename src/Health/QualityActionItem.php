<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Health;

final readonly class QualityActionItem
{
    public function __construct(
        public string $tool,
        public QualitySeverity $severity,
        public string $title,
        public string $description,
        public int $priority,
        public ?string $target = null,
    ) {
    }
}


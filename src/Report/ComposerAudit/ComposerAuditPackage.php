<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\ComposerAudit;

final readonly class ComposerAuditPackage
{
    public function __construct(
        public string $name,
        public string $installedVersion,
        public ?string $replacement,
        public bool $vulnerable,
    ) {
    }

    public function statusLabel(): string
    {
        if ($this->vulnerable) {
            return 'Vulnerable et abandonne';
        }

        return 'Abandonne';
    }
}


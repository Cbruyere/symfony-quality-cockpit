<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\ComposerAudit;

final readonly class ComposerAuditAdvisory
{
    /**
     * @param list<string> $cves
     * @param list<string> $references
     * @param list<string> $sources
     */
    public function __construct(
        public string $id,
        public string $packageName,
        public string $installedVersion,
        public string $advisoryId,
        public string $title,
        public string $severity,
        public string $severityLevel,
        public array $cves,
        public ?string $link,
        public array $references,
        public string $affectedVersions,
        public ?\DateTimeImmutable $publishedAt,
        public array $sources,
        public ?string $description,
        public ?string $status,
    ) {
    }

    public function cveLabel(): string
    {
        return [] === $this->cves ? 'Non renseigne' : implode(', ', $this->cves);
    }

    public function sourceLabel(): string
    {
        return [] === $this->sources ? 'Non renseignee' : implode(', ', $this->sources);
    }
}


<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\ComposerAudit;

final readonly class ComposerAuditReport
{
    /**
     * @param list<ComposerAuditAdvisory> $advisories
     * @param list<ComposerAuditPackage>  $abandonedPackages
     */
    public function __construct(
        public bool $available,
        public bool $valid,
        public ?\DateTimeImmutable $generatedAt,
        public string $freshness,
        public string $statusLabel,
        public string $statusLevel,
        public ?string $jsonReportPath,
        public ComposerAuditMetric $vulnerabilitiesMetric,
        public ComposerAuditMetric $affectedPackagesMetric,
        public ComposerAuditMetric $abandonedPackagesMetric,
        public ComposerAuditMetric $statusMetric,
        public array $advisories,
        public array $abandonedPackages,
    ) {
    }

    public static function unavailable(): self
    {
        $metric = new ComposerAuditMetric('n/a', 'n/a', 'n/a', 'neutral');

        return new self(
            available: false,
            valid: false,
            generatedAt: null,
            freshness: 'indisponible',
            statusLabel: 'Rapport indisponible',
            statusLevel: 'neutral',
            jsonReportPath: null,
            vulnerabilitiesMetric: $metric,
            affectedPackagesMetric: $metric,
            abandonedPackagesMetric: $metric,
            statusMetric: new ComposerAuditMetric('Statut securite', 'Rapport indisponible', 'Rapport indisponible', 'neutral'),
            advisories: [],
            abandonedPackages: [],
        );
    }
}


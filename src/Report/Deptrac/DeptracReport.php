<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Deptrac;

final readonly class DeptracReport
{
    /**
     * @param list<DeptracViolation>     $violations
     * @param list<string>               $layers
     * @param list<DeptracLayerRelation> $layerRelations
     */
    public function __construct(
        public bool $available,
        public bool $valid,
        public \DateTimeImmutable|null $generatedAt,
        public string|null $htmlIndexPath,
        public DeptracMetric $violationsMetric,
        public DeptracMetric $layersMetric,
        public DeptracMetric $rulesMetric,
        public DeptracMetric $forbiddenDependenciesMetric,
        public DeptracMetric $scoreMetric,
        public array $violations,
        public array $layers,
        public int $rulesCount,
        public int $forbiddenDependenciesCount,
        public array $layerRelations,
    ) {
    }

    public static function unavailable(): self
    {
        $metric = new DeptracMetric('n/a', 0, 'n/a', 'neutral');

        return new self(
            available: false,
            valid: false,
            generatedAt: null,
            htmlIndexPath: null,
            violationsMetric: $metric,
            layersMetric: $metric,
            rulesMetric: $metric,
            forbiddenDependenciesMetric: $metric,
            scoreMetric: $metric,
            violations: [],
            layers: [],
            rulesCount: 0,
            forbiddenDependenciesCount: 0,
            layerRelations: [],
        );
    }
}


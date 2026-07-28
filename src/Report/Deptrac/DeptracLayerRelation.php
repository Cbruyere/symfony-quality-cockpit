<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Deptrac;

final readonly class DeptracLayerRelation
{
    public function __construct(
        public string $sourceLayer,
        public string $targetLayer,
        public bool $allowed,
    ) {
    }
}


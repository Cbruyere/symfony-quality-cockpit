<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Deptrac;

final readonly class DeptracViolation
{
    public function __construct(
        public string $id,
        public string $sourceClass,
        public string $targetClass,
        public string $rule,
        public string $sourceLayer,
        public string $targetLayer,
        public string $severity,
        public string $level,
        public string|null $sourceFile,
        public int|null $line,
        public string|null $codeExcerpt,
        public string $htmlReportPath,
    ) {
    }
}


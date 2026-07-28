<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Infection;

final class InfectionStatus
{
    public const KILLED = 'killed';
    public const ESCAPED = 'escaped';
    public const NOT_COVERED = 'not_covered';
    public const ERROR = 'error';
    public const TIMED_OUT = 'timed_out';
    public const SKIPPED = 'skipped';
    public const IGNORED = 'ignored';

    private function __construct()
    {
    }
}


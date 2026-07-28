<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Health;

enum QualitySeverity: string
{
    case HEALTHY = 'healthy';
    case GOOD = 'good';
    case WARNING = 'warning';
    case DEGRADED = 'degraded';
    case CRITICAL = 'critical';
    case UNAVAILABLE = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::HEALTHY => 'Conforme', self::GOOD => 'Très bon', self::WARNING => 'À surveiller',
            self::DEGRADED => 'À améliorer', self::CRITICAL => 'Action requise', self::UNAVAILABLE => 'Indisponible',
        };
    }

    public function cssClasses(): string
    {
        return match ($this) {
            self::HEALTHY => 'border-green-400/30 bg-green-400/10 text-green-200',
            self::GOOD => 'border-cyan-400/30 bg-cyan-400/10 text-cyan-200',
            self::WARNING => 'border-yellow-400/30 bg-yellow-400/10 text-yellow-200',
            self::DEGRADED => 'border-orange-400/30 bg-orange-400/10 text-orange-200',
            self::CRITICAL => 'border-red-400/30 bg-red-400/10 text-red-200',
            self::UNAVAILABLE => 'border-zinc-700 bg-zinc-800 text-zinc-300',
        };
    }

    public function textClasses(): string
    {
        return match ($this) {
            self::HEALTHY => 'text-green-300', self::GOOD => 'text-cyan-300', self::WARNING => 'text-yellow-300',
            self::DEGRADED => 'text-orange-300', self::CRITICAL => 'text-red-300', self::UNAVAILABLE => 'text-zinc-300',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::HEALTHY => 0, self::GOOD => 1, self::WARNING => 2, self::DEGRADED => 3,
            self::CRITICAL => 4, self::UNAVAILABLE => -1,
        };
    }
}


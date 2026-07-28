<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class QualityThresholds
{
    /** @param array<string, array<string, int>> $values */
    public function __construct(#[Autowire('%quality_cockpit.thresholds%')] private array $values)
    {
        $this->validatePercentageThresholds('phpunit', ['excellent', 'good', 'warning', 'degraded']);
        $this->validatePercentageThresholds('infection', ['excellent', 'very_good', 'good', 'warning', 'degraded']);
        $this->validateAscendingThresholds('phpmetrics', ['warning_classes', 'degraded_classes', 'critical_classes'], 0);
    }

    public function get(string $tool, string $threshold): int
    {
        return $this->values[$tool][$threshold] ?? throw new \InvalidArgumentException(sprintf('Missing quality threshold %s.%s.', $tool, $threshold));
    }

    /** @param list<string> $keys */
    private function validatePercentageThresholds(string $tool, array $keys): void
    {
        $this->validateAscendingThresholds($tool, $keys, 0, 100, true);
    }

    /** @param list<string> $keys */
    private function validateAscendingThresholds(string $tool, array $keys, int $minimum, int $maximum = PHP_INT_MAX, bool $descending = false): void
    {
        $previous = null;
        foreach ($keys as $key) {
            $value = $this->values[$tool][$key] ?? null;
            if (!is_int($value) || $value < $minimum || $value > $maximum) {
                throw new \InvalidArgumentException(sprintf('Invalid quality threshold %s.%s.', $tool, $key));
            }
            if (null !== $previous && (($descending && $value >= $previous) || (!$descending && $value <= $previous))) {
                throw new \InvalidArgumentException(sprintf('Quality thresholds are not ordered for %s.', $tool));
            }
            $previous = $value;
        }
    }
}

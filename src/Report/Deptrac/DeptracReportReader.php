<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Deptrac;

use Symfony\Component\Yaml\Yaml;

final class DeptracReportReader
{
    /**
     * @var list<string>
     */
    private const JSON_REPORT_FILES = [
        'report.json',
        'deptrac.json',
        'deptrac-report.json',
    ];

    public function read(string $reportDirectory): DeptracReport
    {
        $payload = $this->readJsonPayload($reportDirectory);

        if (null === $payload) {
            return DeptracReport::unavailable();
        }

        $config = $this->readConfig($reportDirectory);
        $violations = $this->readViolations($payload);
        $violationsCount = $this->readViolationsCount($payload, $violations);
        $layers = $this->readLayers($payload, $config, $violations);
        $layerRelations = $this->readLayerRelations($payload, $config);
        $rulesCount = $this->readRulesCount($payload, $layerRelations);
        $forbiddenDependenciesCount = $this->readForbiddenDependenciesCount($payload, $violations);
        $score = $this->readScore($payload, $violationsCount);
        $htmlIndexPath = is_file($reportDirectory . '/index.html') ? 'index.html' : null;

        return new DeptracReport(
            available: true,
            valid: true,
            generatedAt: $this->readGeneratedAt($reportDirectory),
            htmlIndexPath: $htmlIndexPath,
            violationsMetric: new DeptracMetric('Violations', $violationsCount, (string) $violationsCount, $this->readViolationLevel($violationsCount)),
            layersMetric: new DeptracMetric('Couches', count($layers), (string) count($layers), 'neutral'),
            rulesMetric: new DeptracMetric('Regles', $rulesCount, (string) $rulesCount, 'neutral'),
            forbiddenDependenciesMetric: new DeptracMetric('Dependances interdites', $forbiddenDependenciesCount, (string) $forbiddenDependenciesCount, $this->readViolationLevel($forbiddenDependenciesCount)),
            scoreMetric: new DeptracMetric('Score global', $score, $this->formatScore($score), $this->readScoreLevel($score)),
            violations: $violations,
            layers: $layers,
            rulesCount: $rulesCount,
            forbiddenDependenciesCount: $forbiddenDependenciesCount,
            layerRelations: $layerRelations,
        );
    }

    public function readViolationDetail(string $reportDirectory, string $id): ?DeptracViolation
    {
        foreach ($this->read($reportDirectory)->violations as $violation) {
            if ($violation->id === $id) {
                return $violation;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function readJsonPayload(string $reportDirectory): array|null
    {
        foreach (self::JSON_REPORT_FILES as $fileName) {
            $path = $reportDirectory . '/' . $fileName;

            if (!is_file($path)) {
                continue;
            }

            $content = file_get_contents($path);

            if (false === $content) {
                continue;
            }

            $payload = json_decode($content, true);

            return is_array($payload) ? $payload : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     *
     * @return list<DeptracViolation>
     */
    private function readViolations(array $payload): array
    {
        $rows = $this->extractViolationRows($payload);
        $violations = [];

        foreach ($rows as $index => $row) {
            $sourceClass = $this->readFirstString($row, ['sourceClass', 'source', 'sourceToken', 'depender']);

            if ('' === $sourceClass) {
                $sourceClass = $this->readNestedString($row, 'source', ['class', 'name', 'token']);
            }

            $targetClass = $this->readFirstString($row, ['targetClass', 'target', 'targetToken', 'dependee']);

            if ('' === $targetClass) {
                $targetClass = $this->readNestedString($row, 'target', ['class', 'name', 'token']);
            }

            if ('' === $sourceClass && '' === $targetClass) {
                continue;
            }

            $sourceLayer = $this->readFirstString($row, ['sourceLayer', 'source_layer', 'layer', 'dependerLayer']);
            $targetLayer = $this->readFirstString($row, ['targetLayer', 'target_layer', 'violatedLayer', 'dependeeLayer']);
            $rule = $this->readFirstString($row, ['rule', 'ruleName', 'message', 'reason']);
            $severity = $this->readFirstString($row, ['severity', 'type']);
            $file = $this->readFirstString($row, ['file', 'sourceFile', 'path']);
            $line = $this->readInt($row, 'line') ?? $this->readInt($row, 'sourceLine');
            $codeExcerpt = $this->readFirstString($row, ['code', 'codeExcerpt', 'snippet']);

            $violation = new DeptracViolation(
                id: $this->createId($sourceClass . '|' . $targetClass . '|' . $rule . '|' . $index),
                sourceClass: $sourceClass,
                targetClass: $targetClass,
                rule: '' === $rule ? 'Dependance interdite' : $rule,
                sourceLayer: '' === $sourceLayer ? 'Non classee' : $sourceLayer,
                targetLayer: '' === $targetLayer ? 'Non classee' : $targetLayer,
                severity: '' === $severity ? 'error' : $severity,
                level: $this->readSeverityLevel($severity),
                sourceFile: '' === $file ? null : $file,
                line: $line,
                codeExcerpt: '' === $codeExcerpt ? null : $codeExcerpt,
                htmlReportPath: 'index.html',
            );

            $violations[] = $violation;
        }

        return $violations;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function extractViolationRows(array $payload): array
    {
        if (array_is_list($payload)) {
            return $this->filterRows($payload);
        }

        foreach (['violations', 'errors', 'dependencies'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_is_list($payload[$key])
                    ? $this->filterRows($payload[$key])
                    : $this->filterRows(array_values($payload[$key]));
            }
        }

        if (isset($payload['files']) && is_array($payload['files'])) {
            return $this->extractFileViolationRows($payload['files']);
        }

        return [];
    }

    /**
     * @param array<mixed> $files
     *
     * @return list<array<string, mixed>>
     */
    private function extractFileViolationRows(array $files): array
    {
        $rows = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $filePath = $this->readFirstString($file, ['file', 'path', 'filename']);

            foreach (['violations', 'errors', 'dependencies'] as $key) {
                if (!isset($file[$key]) || !is_array($file[$key])) {
                    continue;
                }

                foreach ($file[$key] as $violation) {
                    if (!is_array($violation)) {
                        continue;
                    }

                    if ('' !== $filePath && !isset($violation['file'])) {
                        $violation['file'] = $filePath;
                    }

                    $rows[] = $violation;
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function filterRows(array $rows): array
    {
        $filteredRows = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $filteredRows[] = $row;
            }
        }

        return $filteredRows;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @param array<string, mixed>             $config
     * @param list<DeptracViolation>           $violations
     *
     * @return list<string>
     */
    private function readLayers(array $payload, array $config, array $violations): array
    {
        $layers = [];

        if (isset($payload['layers']) && is_array($payload['layers'])) {
            foreach ($payload['layers'] as $key => $layer) {
                if (is_string($layer)) {
                    $layers[] = $layer;
                    continue;
                }

                if (is_string($key)) {
                    $layers[] = $key;
                }
            }
        }

        $configuredLayers = $config['layers'] ?? null;

        if (is_array($configuredLayers)) {
            foreach ($configuredLayers as $layer) {
                if (!is_array($layer)) {
                    continue;
                }

                $name = $this->readFirstString($layer, ['name']);

                if ('' !== $name) {
                    $layers[] = $name;
                }
            }
        }

        foreach ($violations as $violation) {
            $layers[] = $violation->sourceLayer;
            $layers[] = $violation->targetLayer;
        }

        $layers = array_values(array_unique(array_filter(
            $layers,
            static fn (string $layer): bool => '' !== $layer && 'Non classee' !== $layer,
        )));
        sort($layers);

        return $layers;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @param array<string, mixed>             $config
     *
     * @return list<DeptracLayerRelation>
     */
    private function readLayerRelations(array $payload, array $config): array
    {
        $ruleset = $payload['ruleset'] ?? $config['ruleset'] ?? null;

        if (!is_array($ruleset)) {
            return [];
        }

        $relations = [];

        foreach ($ruleset as $sourceLayer => $targets) {
            if (!is_string($sourceLayer) || !is_array($targets)) {
                continue;
            }

            foreach ($targets as $targetLayer) {
                if (is_string($targetLayer)) {
                    $relations[] = new DeptracLayerRelation($sourceLayer, $targetLayer, true);
                }
            }
        }

        return $relations;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @param list<DeptracLayerRelation>      $relations
     */
    private function readRulesCount(array $payload, array $relations): int
    {
        $summary = $this->readSummary($payload);
        $summaryRules = $this->readInt($summary, 'rules');

        if (null !== $summaryRules) {
            return $summaryRules;
        }

        if (isset($payload['rules']) && is_array($payload['rules'])) {
            return count($payload['rules']);
        }

        return count($relations);
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @param list<DeptracViolation>          $violations
     */
    private function readForbiddenDependenciesCount(array $payload, array $violations): int
    {
        $summary = $this->readSummary($payload);
        $summaryForbidden = $this->readInt($summary, 'forbiddenDependencies');

        if (null !== $summaryForbidden) {
            return $summaryForbidden;
        }

        $report = $this->readNativeReport($payload);
        $nativeViolations = $this->readInt($report, 'Violations');

        if (null !== $nativeViolations) {
            return $nativeViolations;
        }

        $dependencies = [];

        foreach ($violations as $violation) {
            $dependencies[] = $violation->sourceClass . '->' . $violation->targetClass;
        }

        return count(array_unique($dependencies));
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @param list<DeptracViolation>          $violations
     */
    private function readViolationsCount(array $payload, array $violations): int
    {
        $summary = $this->readSummary($payload);
        $summaryViolations = $this->readInt($summary, 'violations');

        if (null !== $summaryViolations) {
            return $summaryViolations;
        }

        $report = $this->readNativeReport($payload);
        $nativeViolations = $this->readInt($report, 'Violations');

        if (null !== $nativeViolations) {
            return $nativeViolations;
        }

        return count($violations);
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     */
    private function readScore(array $payload, int $violationsCount): float
    {
        $summary = $this->readSummary($payload);
        $score = $this->readFloat($summary, 'score');

        if (null !== $score) {
            return $score;
        }

        return max(0.0, 100.0 - ($violationsCount * 10.0));
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function readSummary(array $payload): array
    {
        if (isset($payload['summary']) && is_array($payload['summary']) && !array_is_list($payload['summary'])) {
            return $payload['summary'];
        }

        return [];
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function readNativeReport(array $payload): array
    {
        if (isset($payload['Report']) && is_array($payload['Report']) && !array_is_list($payload['Report'])) {
            return $payload['Report'];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(string $reportDirectory): array
    {
        foreach (['deptrac.yaml', 'deptrac.yml'] as $fileName) {
            $path = $reportDirectory . '/' . $fileName;

            if (!is_file($path)) {
                continue;
            }

            $config = Yaml::parseFile($path);

            if (is_array($config)) {
                $deptrac = $config['deptrac'] ?? $config;

                return is_array($deptrac) ? $deptrac : [];
            }
        }

        return [];
    }

    private function readGeneratedAt(string $reportDirectory): \DateTimeImmutable|null
    {
        foreach (['report.json', 'deptrac.json', 'index.html'] as $fileName) {
            $path = $reportDirectory . '/' . $fileName;

            if (is_file($path)) {
                return (new \DateTimeImmutable())->setTimestamp((int) filemtime($path));
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>        $keys
     */
    private function readFirstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if (is_scalar($value) && '' !== trim((string) $value)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>        $keys
     */
    private function readNestedString(array $row, string $parentKey, array $keys): string
    {
        $parent = $row[$parentKey] ?? null;

        if (!is_array($parent)) {
            return '';
        }

        return $this->readFirstString($parent, $keys);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function readInt(array $row, string $key): int|null
    {
        $value = $row[$key] ?? null;

        if (null === $value || '' === $value || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function readFloat(array $row, string $key): float|null
    {
        $value = $row[$key] ?? null;

        if (null === $value || '' === $value || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function readViolationLevel(int $count): string
    {
        return match (true) {
            0 === $count => 'success',
            $count <= 5 => 'warning',
            default => 'danger',
        };
    }

    private function readScoreLevel(float $score): string
    {
        return match (true) {
            $score >= 95.0 => 'success',
            $score >= 80.0 => 'warning',
            default => 'danger',
        };
    }

    private function readSeverityLevel(string $severity): string
    {
        return match (strtolower($severity)) {
            'warning', 'warn' => 'warning',
            'info', 'notice' => 'neutral',
            default => 'danger',
        };
    }

    private function formatScore(float $score): string
    {
        return rtrim(rtrim(number_format($score, 2, '.', ' '), '0'), '.') . '%';
    }

    private function createId(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}


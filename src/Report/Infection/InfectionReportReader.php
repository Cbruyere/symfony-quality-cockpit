<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\Infection;

final class InfectionReportReader
{
    private const RECENT_MAX_AGE = 86400;
    private const REFRESH_MAX_AGE = 604800;

    public function read(string $directory): InfectionReport
    {
        $reportPath = $directory.'/report.json';
        $summaryPath = $directory.'/summary.json';
        $payload = $this->readJson($reportPath);
        $summary = $this->readJson($summaryPath);

        if (null === $payload && null === $summary) {
            return InfectionReport::unavailable(is_file($reportPath) || is_file($summaryPath) ? 'JSON Infection invalide' : 'Aucun rapport Infection disponible');
        }

        $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : (is_array($summary['stats'] ?? null) ? $summary['stats'] : []);
        $mutants = $this->readMutants($payload ?? []);
        $metrics = $this->readMetrics($stats, $mutants);

        return new InfectionReport(
            true,
            [] !== $stats || [] !== $mutants,
            [] === $stats && [] === $mutants ? 'Rapport vide' : '',
            $this->freshness($directory),
            $this->generatedAt($directory),
            $this->readVersion($payload ?? [], $summary ?? []),
            $metrics,
            $mutants,
            $this->aggregateClasses($mutants),
            $this->aggregateMutators($mutants),
            is_file($directory.'/index.html') ? 'index.html' : null,
            is_file($reportPath) ? 'report.json' : null,
            is_file($summaryPath) ? 'summary.json' : null,
            is_file($directory.'/infection.log') ? 'infection.log' : null,
        );
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $value = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $stats
     * @param list<InfectionMutant> $mutants
     */
    private function readMetrics(array $stats, array $mutants): InfectionMetrics
    {
        $value = static fn (string $key, int $fallback = 0): int => isset($stats[$key]) && is_numeric($stats[$key]) ? (int) $stats[$key] : $fallback;
        $percentage = static fn (string $key): ?float => isset($stats[$key]) && is_numeric($stats[$key]) ? (float) $stats[$key] : null;

        return new InfectionMetrics(
            $value('totalMutantsCount', count($mutants)),
            $value('killedCount'),
            $value('escapedCount'),
            $value('notCoveredCount'),
            $value('errorCount'),
            $value('timeOutCount'),
            $value('skippedCount'),
            $value('ignoredCount'),
            $percentage('msi'),
            $percentage('mutationCodeCoverage'),
            $percentage('coveredCodeMsi'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<InfectionMutant>
     */
    private function readMutants(array $payload): array
    {
        $mutants = [];
        $categories = [
            'killed' => InfectionStatus::KILLED, 'escaped' => InfectionStatus::ESCAPED,
            'uncovered' => InfectionStatus::NOT_COVERED, 'errored' => InfectionStatus::ERROR,
            'timeouted' => InfectionStatus::TIMED_OUT, 'skipped' => InfectionStatus::SKIPPED,
            'ignored' => InfectionStatus::IGNORED,
        ];

        foreach ($categories as $key => $status) {
            foreach (($payload[$key] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $mutants[] = $this->createMutant($row, $status);
            }
        }

        usort($mutants, static fn (InfectionMutant $a, InfectionMutant $b): int => strcmp($a->status === InfectionStatus::ESCAPED ? '0' : '1', $b->status === InfectionStatus::ESCAPED ? '0' : '1') ?: strcmp($a->className ?? '', $b->className ?? ''));

        return $mutants;
    }

    /** @param array<string, mixed> $row */
    private function createMutant(array $row, string $status): InfectionMutant
    {
        $mutator = is_array($row['mutator'] ?? null) ? $row['mutator'] : [];
        $file = $this->stringValue($mutator, 'originalFilePath') ?? $this->stringValue($row, 'originalFilePath');
        $source = $this->stringValue($mutator, 'originalSourceCode') ?? $this->stringValue($row, 'originalSourceCode');
        $class = $this->stringValue($row, 'className') ?? $this->classFromSource($source);
        $diff = $this->stringValue($row, 'diff');
        $line = is_numeric($mutator['originalStartLine'] ?? null) ? (int) $mutator['originalStartLine'] : (is_numeric($row['originalStartLine'] ?? null) ? (int) $row['originalStartLine'] : null);
        $id = sha1(implode('|', [$status, $class, $file, (string) ($line ?? ''), $this->stringValue($mutator, 'mutatorName') ?? '', $diff ?? '']));

        return new InfectionMutant($id, $status, $class, $this->stringValue($row, 'methodName'), $file, $line, $this->stringValue($mutator, 'mutatorName'), $source, $this->stringValue($mutator, 'mutatedSourceCode') ?? $this->stringValue($row, 'mutatedSourceCode'), $diff, $this->stringValue($row, 'processOutput'));
    }

    /** @param array<string, mixed> $row */
    private function stringValue(array $row, string $key): ?string
    {
        return isset($row[$key]) && is_string($row[$key]) && '' !== $row[$key] ? $row[$key] : null;
    }

    private function classFromSource(?string $source): ?string
    {
        if (null === $source || 1 !== preg_match('/namespace\s+([^;]+);.*?class\s+(\w+)/s', $source, $matches)) {
            return null;
        }

        return trim($matches[1]).'\\'.$matches[2];
    }

    /**
     * @param list<InfectionMutant> $mutants
     * @return list<InfectionClassSummary>
     */
    private function aggregateClasses(array $mutants): array
    {
        $groups = [];
        foreach ($mutants as $mutant) {
            $name = $mutant->className ?? 'Classe inconnue';
            $groups[$name] ??= ['total' => 0, 'killed' => 0, 'escaped' => 0, 'notCovered' => 0, 'mutators' => []];
            ++$groups[$name]['total'];
            $counter = match ($mutant->status) {
                InfectionStatus::KILLED => 'killed', InfectionStatus::ESCAPED => 'escaped', InfectionStatus::NOT_COVERED => 'notCovered', default => null,
            };
            if (null !== $counter) ++$groups[$name][$counter];
            if (in_array($mutant->status, [InfectionStatus::ESCAPED, InfectionStatus::NOT_COVERED], true) && null !== $mutant->mutator) {
                $groups[$name]['mutators'][$mutant->mutator] = true;
            }
        }
        $result = [];
        foreach ($groups as $name => $group) {
            $covered = $group['total'] - $group['notCovered'];
            $result[] = new InfectionClassSummary($name, $group['total'], $group['killed'], $group['escaped'], $group['notCovered'], $covered > 0 ? round($group['killed'] / $covered * 100, 2) : null, array_keys($group['mutators']));
        }
        usort($result, static fn (InfectionClassSummary $a, InfectionClassSummary $b): int => $b->escaped <=> $a->escaped ?: strcmp($a->className, $b->className));
        return $result;
    }

    /**
     * @param list<InfectionMutant> $mutants
     * @return list<InfectionMutatorSummary>
     */
    private function aggregateMutators(array $mutants): array
    {
        $groups = [];
        foreach ($mutants as $mutant) {
            $name = $mutant->mutator ?? 'Inconnu';
            $groups[$name] ??= ['total' => 0, 'killed' => 0, 'escaped' => 0, 'errors' => 0, 'timeouts' => 0];
            ++$groups[$name]['total'];
            if ($mutant->status === InfectionStatus::KILLED) ++$groups[$name]['killed'];
            if ($mutant->status === InfectionStatus::ESCAPED) ++$groups[$name]['escaped'];
            if ($mutant->status === InfectionStatus::ERROR) ++$groups[$name]['errors'];
            if ($mutant->status === InfectionStatus::TIMED_OUT) ++$groups[$name]['timeouts'];
        }
        $result = [];
        foreach ($groups as $name => $group) {
            $result[] = new InfectionMutatorSummary($name, $group['total'], $group['killed'], $group['escaped'], $group['errors'], $group['timeouts'], round($group['killed'] / $group['total'] * 100, 2));
        }
        usort($result, static fn (InfectionMutatorSummary $a, InfectionMutatorSummary $b): int => ($a->efficiency ?? 0) <=> ($b->efficiency ?? 0));
        return $result;
    }

    private function freshness(string $directory): string
    {
        $mtime = @filemtime($directory.'/report.json');
        if (false === $mtime) $mtime = @filemtime($directory.'/summary.json');
        if (false === $mtime) return 'indisponible';
        $age = time() - $mtime;
        return $age <= self::RECENT_MAX_AGE ? 'recent' : ($age <= self::REFRESH_MAX_AGE ? 'a regenerer' : 'obsolete');
    }

    private function generatedAt(string $directory): ?\DateTimeImmutable
    {
        $mtime = @filemtime($directory.'/report.json');
        if (false === $mtime) $mtime = @filemtime($directory.'/summary.json');
        return false === $mtime ? null : (new \DateTimeImmutable())->setTimestamp($mtime);
    }

    /** @param array<string, mixed> ...$payloads */
    private function readVersion(array ...$payloads): ?string
    {
        foreach ($payloads as $payload) {
            foreach (['infectionVersion', 'version'] as $key) if (is_string($payload[$key] ?? null)) return $payload[$key];
        }
        return null;
    }
}


<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\ComposerAudit;

final class ComposerAuditReportReader
{
    private const REPORT_FILE = 'report.json';
    private const RECENT_MAX_AGE = 86400;
    private const REFRESH_MAX_AGE = 604800;

    public function read(string $reportDirectory): ComposerAuditReport
    {
        $payload = $this->readPayload($reportDirectory);

        if (null === $payload) {
            return ComposerAuditReport::unavailable();
        }

        $advisories = $this->readAdvisories($payload);
        $abandonedPackages = $this->readAbandonedPackages($payload, $advisories);
        $affectedPackagesCount = count(array_unique(array_map(
            static fn (ComposerAuditAdvisory $advisory): string => $advisory->packageName,
            $advisories,
        )));
        $abandonedPackagesCount = count($abandonedPackages);
        [$statusLabel, $statusLevel] = $this->readStatus(count($advisories), $abandonedPackagesCount);

        return new ComposerAuditReport(
            available: true,
            valid: true,
            generatedAt: $this->readGeneratedAt($reportDirectory),
            freshness: $this->readFreshness($reportDirectory),
            statusLabel: $statusLabel,
            statusLevel: $statusLevel,
            jsonReportPath: self::REPORT_FILE,
            vulnerabilitiesMetric: new ComposerAuditMetric('Vulnerabilites', count($advisories), (string) count($advisories), $this->readVulnerabilityLevel(count($advisories))),
            affectedPackagesMetric: new ComposerAuditMetric('Packages affectes', $affectedPackagesCount, (string) $affectedPackagesCount, $this->readVulnerabilityLevel(count($advisories))),
            abandonedPackagesMetric: new ComposerAuditMetric('Packages abandonnes', $abandonedPackagesCount, (string) $abandonedPackagesCount, 0 === count($advisories) && $abandonedPackagesCount > 0 ? 'warning' : 'neutral'),
            statusMetric: new ComposerAuditMetric('Statut securite', $statusLabel, $statusLabel, $statusLevel),
            advisories: $advisories,
            abandonedPackages: $abandonedPackages,
        );
    }

    public function readAdvisoryDetail(string $reportDirectory, string $id): ?ComposerAuditAdvisory
    {
        foreach ($this->read($reportDirectory)->advisories as $advisory) {
            if ($advisory->id === $id) {
                return $advisory;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPayload(string $reportDirectory): ?array
    {
        $path = $reportDirectory.'/'.self::REPORT_FILE;

        if (!is_file($path) || 0 === filesize($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if (false === $content) {
            return null;
        }

        $payload = json_decode($content, true);

        return is_array($payload) && !array_is_list($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<ComposerAuditAdvisory>
     */
    private function readAdvisories(array $payload): array
    {
        $rows = $this->extractAdvisoryRows($payload['advisories'] ?? []);
        $advisories = [];

        foreach ($rows as $index => $row) {
            $packageName = $this->readPackageName($row);

            if ('' === $packageName) {
                continue;
            }

            $advisoryId = $this->readFirstString($row, ['advisoryId', 'advisory_id', 'id', 'remoteId']);
            $title = $this->readFirstString($row, ['title', 'summary']);

            if ('' === $advisoryId && '' === $title) {
                continue;
            }

            $severity = $this->normalizeSeverity($this->readFirstString($row, ['severity', 'cvssSeverity']));
            $link = $this->readUrl($this->readFirstString($row, ['link', 'url']));
            $references = $this->readReferences($row, $link);
            $cves = $this->readCves($row);
            $sources = $this->readSources($row);

            $advisories[] = new ComposerAuditAdvisory(
                id: $this->createId($packageName.'|'.$advisoryId.'|'.$title.'|'.$index),
                packageName: $packageName,
                installedVersion: $this->readFirstString($row, ['version', 'installedVersion', 'installed_version']) ?: 'Non renseignee',
                advisoryId: '' === $advisoryId ? 'Non renseigne' : $advisoryId,
                title: '' === $title ? 'Advisory Composer' : $title,
                severity: $severity,
                severityLevel: $this->readSeverityLevel($severity),
                cves: $cves,
                link: $link,
                references: $references,
                affectedVersions: $this->readFirstString($row, ['affectedVersions', 'affected_versions', 'affected']) ?: 'Non renseignees',
                publishedAt: $this->readDate($row, ['reportedAt', 'reported_at', 'publishedAt', 'published_at']),
                sources: $sources,
                description: $this->readFirstString($row, ['description', 'details']) ?: null,
                status: $this->readFirstString($row, ['status']) ?: null,
            );
        }

        return $advisories;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractAdvisoryRows(mixed $advisoriesPayload): array
    {
        if (!is_array($advisoriesPayload)) {
            return [];
        }

        $rows = [];

        if (array_is_list($advisoriesPayload)) {
            foreach ($advisoriesPayload as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        foreach ($advisoriesPayload as $packageName => $packageAdvisories) {
            if (!is_array($packageAdvisories)) {
                continue;
            }

            $packageRows = array_is_list($packageAdvisories) ? $packageAdvisories : [$packageAdvisories];

            foreach ($packageRows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (is_string($packageName) && !isset($row['packageName'])) {
                    $row['packageName'] = $packageName;
                }

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>        $payload
     * @param list<ComposerAuditAdvisory> $advisories
     *
     * @return list<ComposerAuditPackage>
     */
    private function readAbandonedPackages(array $payload, array $advisories): array
    {
        $abandoned = $payload['abandoned'] ?? [];

        if (!is_array($abandoned)) {
            return [];
        }

        $vulnerablePackages = array_flip(array_map(
            static fn (ComposerAuditAdvisory $advisory): string => $advisory->packageName,
            $advisories,
        ));
        $packages = [];

        foreach ($abandoned as $packageName => $replacement) {
            if (is_array($replacement)) {
                $packageName = $this->readFirstString($replacement, ['name', 'package', 'packageName']) ?: (is_string($packageName) ? $packageName : '');
                $replacement = $this->readFirstString($replacement, ['replacement', 'suggestedReplacement', 'recommendedPackage']);
            }

            if (!is_string($packageName) || '' === $packageName) {
                continue;
            }

            $replacementValue = is_string($replacement) && '' !== $replacement ? $replacement : null;

            $packages[] = new ComposerAuditPackage(
                name: $packageName,
                installedVersion: 'Non renseignee',
                replacement: $replacementValue,
                vulnerable: isset($vulnerablePackages[$packageName]),
            );
        }

        return $packages;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function readPackageName(array $row): string
    {
        $packageName = $this->readFirstString($row, ['packageName', 'package', 'name']);

        if ('' !== $packageName) {
            return $packageName;
        }

        if (isset($row['affectedPackage']) && is_array($row['affectedPackage'])) {
            return $this->readFirstString($row['affectedPackage'], ['name', 'packageName']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function readCves(array $row): array
    {
        $cves = $this->readStringList($row['cve'] ?? null);

        if ([] === $cves) {
            $cves = $this->readStringList($row['cves'] ?? null);
        }

        return array_values(array_unique($cves));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function readReferences(array $row, ?string $link): array
    {
        $references = [];

        foreach ($this->readStringList($row['references'] ?? null) as $reference) {
            $url = $this->readUrl($reference);

            if (null !== $url) {
                $references[] = $url;
            }
        }

        if (null !== $link) {
            $references[] = $link;
        }

        return array_values(array_unique($references));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function readSources(array $row): array
    {
        $sources = [];
        $sourcePayload = $row['sources'] ?? $row['source'] ?? null;

        if (is_string($sourcePayload) && '' !== $sourcePayload) {
            return [$sourcePayload];
        }

        if (!is_array($sourcePayload)) {
            return [];
        }

        $sourceRows = array_is_list($sourcePayload) ? $sourcePayload : [$sourcePayload];

        foreach ($sourceRows as $sourceRow) {
            if (is_string($sourceRow) && '' !== $sourceRow) {
                $sources[] = $sourceRow;
                continue;
            }

            if (!is_array($sourceRow)) {
                continue;
            }

            $sourceName = $this->readFirstString($sourceRow, ['name', 'remoteId', 'type']);

            if ('' !== $sourceName) {
                $sources[] = $sourceName;
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $keys
     */
    private function readDate(array $row, array $keys): ?\DateTimeImmutable
    {
        $value = $this->readFirstString($row, $keys);

        if ('' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function readGeneratedAt(string $reportDirectory): ?\DateTimeImmutable
    {
        $path = $reportDirectory.'/'.self::REPORT_FILE;

        if (!is_file($path)) {
            return null;
        }

        $mtime = filemtime($path);

        return false === $mtime ? null : (new \DateTimeImmutable())->setTimestamp($mtime);
    }

    private function readFreshness(string $reportDirectory): string
    {
        $path = $reportDirectory.'/'.self::REPORT_FILE;
        $mtime = is_file($path) ? filemtime($path) : false;

        if (false === $mtime) {
            return 'indisponible';
        }

        $age = time() - $mtime;

        if ($age <= self::RECENT_MAX_AGE) {
            return 'recent';
        }

        if ($age <= self::REFRESH_MAX_AGE) {
            return 'a regenerer';
        }

        return 'obsolete';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function readStatus(int $vulnerabilitiesCount, int $abandonedPackagesCount): array
    {
        if ($vulnerabilitiesCount > 0) {
            return ['Vulnerable', 'danger'];
        }

        if ($abandonedPackagesCount > 0) {
            return ['Attention', 'warning'];
        }

        return ['Sain', 'success'];
    }

    private function readVulnerabilityLevel(int $vulnerabilitiesCount): string
    {
        return $vulnerabilitiesCount > 0 ? 'danger' : 'success';
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return '' === $severity ? 'unknown' : $severity;
    }

    private function readSeverityLevel(string $severity): string
    {
        return match ($severity) {
            'critical', 'high' => 'danger',
            'medium', 'moderate', 'low' => 'warning',
            default => 'neutral',
        };
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $keys
     */
    private function readFirstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && (is_string($row[$key]) || is_numeric($row[$key]))) {
                return trim((string) $row[$key]);
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function readStringList(mixed $payload): array
    {
        if (is_string($payload) && '' !== $payload) {
            return [$payload];
        }

        if (!is_array($payload)) {
            return [];
        }

        $values = [];

        foreach ($payload as $value) {
            if (is_string($value) && '' !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function readUrl(string $url): ?string
    {
        if ('' === $url) {
            return null;
        }

        return filter_var($url, \FILTER_VALIDATE_URL) && in_array(parse_url($url, \PHP_URL_SCHEME), ['http', 'https'], true)
            ? $url
            : null;
    }

    private function createId(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }
}


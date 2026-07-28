<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Tests\Unit\Report\ComposerAudit;

use Chrisdev\QualityCockpit\Report\ComposerAudit\ComposerAuditReportReader;
use PHPUnit\Framework\TestCase;

final class ComposerAuditReportReaderTest extends TestCase
{
    public function testReadReturnsUnavailableReportWhenReportIsMissing(): void
    {
        $report = (new ComposerAuditReportReader())->read($this->createReportDirectory());

        self::assertFalse($report->available);
        self::assertFalse($report->valid);
        self::assertSame('Rapport indisponible', $report->statusLabel);
    }

    public function testReadReturnsUnavailableReportWhenJsonIsInvalid(): void
    {
        $directory = $this->createReportDirectory();
        file_put_contents($directory.'/report.json', '{invalid json');

        $report = (new ComposerAuditReportReader())->read($directory);

        self::assertFalse($report->available);
        self::assertFalse($report->valid);
    }

    public function testReadParsesHealthyReport(): void
    {
        $directory = $this->createReportDirectory();
        $this->writeReport($directory, [
            'advisories' => [],
            'abandoned' => [],
            'filter' => [],
        ]);

        $report = (new ComposerAuditReportReader())->read($directory);

        self::assertTrue($report->available);
        self::assertTrue($report->valid);
        self::assertSame('Sain', $report->statusLabel);
        self::assertSame('success', $report->statusLevel);
        self::assertSame(0, $report->vulnerabilitiesMetric->value);
        self::assertSame(0, $report->affectedPackagesMetric->value);
        self::assertSame(0, $report->abandonedPackagesMetric->value);
    }

    public function testReadParsesOneVulnerability(): void
    {
        $directory = $this->createReportDirectory();
        $this->writeReport($directory, [
            'advisories' => [
                'symfony/http-foundation' => [
                    [
                        'advisoryId' => 'PKSA-123',
                        'packageName' => 'symfony/http-foundation',
                        'title' => 'Header injection',
                        'severity' => 'high',
                        'cve' => 'CVE-2026-0001',
                        'link' => 'https://example.com/advisory',
                        'affectedVersions' => '<8.0.1',
                        'reportedAt' => '2026-01-15T10:00:00+00:00',
                        'sources' => [['name' => 'FriendsOfPHP/security-advisories']],
                        'version' => '8.0.0',
                    ],
                ],
            ],
            'abandoned' => [],
        ]);

        $reader = new ComposerAuditReportReader();
        $report = $reader->read($directory);
        $advisory = $report->advisories[0];

        self::assertSame('Vulnerable', $report->statusLabel);
        self::assertSame('danger', $report->statusLevel);
        self::assertSame(1, $report->vulnerabilitiesMetric->value);
        self::assertSame(1, $report->affectedPackagesMetric->value);
        self::assertSame('symfony/http-foundation', $advisory->packageName);
        self::assertSame('8.0.0', $advisory->installedVersion);
        self::assertSame('PKSA-123', $advisory->advisoryId);
        self::assertSame('high', $advisory->severity);
        self::assertSame('danger', $advisory->severityLevel);
        self::assertSame(['CVE-2026-0001'], $advisory->cves);
        self::assertSame('https://example.com/advisory', $advisory->link);
        self::assertSame('<8.0.1', $advisory->affectedVersions);
        self::assertSame(['FriendsOfPHP/security-advisories'], $advisory->sources);

        $detail = $reader->readAdvisoryDetail($directory, $advisory->id);

        self::assertNotNull($detail);
        self::assertSame('PKSA-123', $detail->advisoryId);
    }

    public function testReadParsesMultipleVulnerabilitiesAcrossPackages(): void
    {
        $directory = $this->createReportDirectory();
        $this->writeReport($directory, [
            'advisories' => [
                'vendor/one' => [
                    ['advisoryId' => 'ONE-1', 'title' => 'First', 'severity' => 'medium'],
                ],
                'vendor/two' => [
                    ['advisoryId' => 'TWO-1', 'title' => 'Second', 'severity' => 'critical'],
                ],
            ],
            'abandoned' => [],
        ]);

        $report = (new ComposerAuditReportReader())->read($directory);

        self::assertSame(2, $report->vulnerabilitiesMetric->value);
        self::assertSame(2, $report->affectedPackagesMetric->value);
        self::assertSame('warning', $report->advisories[0]->severityLevel);
        self::assertSame('danger', $report->advisories[1]->severityLevel);
    }

    public function testReadParsesMultipleAdvisoriesForSamePackage(): void
    {
        $directory = $this->createReportDirectory();
        $this->writeReport($directory, [
            'advisories' => [
                'vendor/package' => [
                    ['advisoryId' => 'PKSA-1', 'title' => 'First'],
                    ['advisoryId' => 'PKSA-2', 'title' => 'Second'],
                ],
            ],
            'abandoned' => [],
        ]);

        $report = (new ComposerAuditReportReader())->read($directory);

        self::assertSame(2, $report->vulnerabilitiesMetric->value);
        self::assertSame(1, $report->affectedPackagesMetric->value);
        self::assertSame('PKSA-1', $report->advisories[0]->advisoryId);
        self::assertSame('PKSA-2', $report->advisories[1]->advisoryId);
    }

    public function testReadKeepsUnknownValuesWhenCveAndSeverityAreMissing(): void
    {
        $directory = $this->createReportDirectory();
        $this->writeReport($directory, [
            'advisories' => [
                'vendor/package' => [
                    ['advisoryId' => 'PKSA-1', 'title' => 'No severity'],
                ],
            ],
            'abandoned' => [],
        ]);

        $advisory = (new ComposerAuditReportReader())->read($directory)->advisories[0];

        self::assertSame([], $advisory->cves);
        self::assertSame('Non renseigne', $advisory->cveLabel());
        self::assertSame('unknown', $advisory->severity);
        self::assertSame('neutral', $advisory->severityLevel);
    }

    public function testReadParsesAbandonedPackagesWithAndWithoutReplacement(): void
    {
        $directory = $this->createReportDirectory();
        $this->writeReport($directory, [
            'advisories' => [],
            'abandoned' => [
                'legacy/with-replacement' => 'modern/package',
                'legacy/without-replacement' => null,
            ],
        ]);

        $report = (new ComposerAuditReportReader())->read($directory);

        self::assertSame('Attention', $report->statusLabel);
        self::assertSame('warning', $report->statusLevel);
        self::assertSame(2, $report->abandonedPackagesMetric->value);
        self::assertSame('modern/package', $report->abandonedPackages[0]->replacement);
        self::assertNull($report->abandonedPackages[1]->replacement);
    }

    public function testReadMarksPackageAsVulnerableAndAbandoned(): void
    {
        $directory = $this->createReportDirectory();
        $this->writeReport($directory, [
            'advisories' => [
                'legacy/package' => [
                    ['advisoryId' => 'PKSA-1', 'title' => 'Vulnerable'],
                ],
            ],
            'abandoned' => [
                'legacy/package' => 'modern/package',
            ],
        ]);

        $package = (new ComposerAuditReportReader())->read($directory)->abandonedPackages[0];

        self::assertTrue($package->vulnerable);
        self::assertSame('Vulnerable et abandonne', $package->statusLabel());
    }

    public function testReadIgnoresUnknownPartialStructureWithoutFailing(): void
    {
        $directory = $this->createReportDirectory();
        $this->writeReport($directory, [
            'advisories' => [
                'vendor/package' => [
                    ['unexpected' => 'value'],
                    ['packageName' => 'vendor/valid', 'title' => 'Valid advisory'],
                ],
            ],
            'abandoned' => 'invalid',
            'unknown' => ['value'],
        ]);

        $report = (new ComposerAuditReportReader())->read($directory);

        self::assertTrue($report->available);
        self::assertSame(1, $report->vulnerabilitiesMetric->value);
        self::assertSame('vendor/valid', $report->advisories[0]->packageName);
    }

    public function testReadMarksOldReportAsObsolete(): void
    {
        $directory = $this->createReportDirectory();
        $this->writeReport($directory, [
            'advisories' => [],
            'abandoned' => [],
        ]);
        touch($directory.'/report.json', time() - 691200);

        $report = (new ComposerAuditReportReader())->read($directory);

        self::assertSame('obsolete', $report->freshness);
    }

    private function createReportDirectory(): string
    {
        $directory = sys_get_temp_dir().'/composer-audit-report-'.bin2hex(random_bytes(8));
        mkdir($directory, recursive: true);

        return $directory;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeReport(string $directory, array $payload): void
    {
        file_put_contents($directory.'/report.json', json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}


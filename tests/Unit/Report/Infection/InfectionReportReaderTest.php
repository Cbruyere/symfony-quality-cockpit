<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Tests\Unit\Report\Infection;

use Chrisdev\QualityCockpit\Report\Infection\InfectionReportReader;
use PHPUnit\Framework\TestCase;

final class InfectionReportReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/infection-reader-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
    }

    public function testReadsRealInfectionShapeAndAggregatesSurvivors(): void
    {
        file_put_contents($this->directory.'/report.json', json_encode(self::fixture(), JSON_THROW_ON_ERROR));

        $report = (new InfectionReportReader())->read($this->directory);

        self::assertTrue($report->available);
        self::assertTrue($report->valid);
        self::assertSame(3, $report->metrics->total);
        self::assertSame(1, $report->metrics->escaped);
        self::assertSame(1, $report->metrics->notCovered);
        self::assertSame(50.0, $report->metrics->coveredCodeMsi);
        self::assertCount(3, $report->mutants);
        self::assertSame('App\\Demo\\Calculator', $report->classes[0]->className);
        self::assertSame('Minus', $report->mutators[0]->mutator);
    }

    public function testHandlesMissingAndInvalidReports(): void
    {
        $reader = new InfectionReportReader();
        self::assertFalse($reader->read($this->directory)->available);

        file_put_contents($this->directory.'/report.json', '{invalid');
        $report = $reader->read($this->directory);

        self::assertFalse($report->valid);
        self::assertSame('JSON Infection invalide', $report->message);
    }

    public function testReadsSummaryWhenDetailedReportIsAbsent(): void
    {
        file_put_contents($this->directory.'/summary.json', json_encode(['stats' => self::fixture()['stats']], JSON_THROW_ON_ERROR));

        $report = (new InfectionReportReader())->read($this->directory);

        self::assertTrue($report->valid);
        self::assertSame(3, $report->metrics->total);
        self::assertSame([], $report->mutants);
    }

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        $mutant = static fn (string $mutator, string $status): array => [
            'mutator' => ['mutatorName' => $mutator],
            'originalFilePath' => '/app/src/Demo/Calculator.php',
            'originalStartLine' => 12,
            'originalSourceCode' => "<?php namespace App\\Demo; final class Calculator {}",
            'mutatedSourceCode' => "<?php namespace App\\Demo; final class Calculator { /* mutant */ }",
            'diff' => '- original\n+ mutant',
            'processOutput' => 'PHPUnit OK',
            '_status' => $status,
        ];

        return [
            'stats' => ['totalMutantsCount' => 3, 'killedCount' => 1, 'notCoveredCount' => 1, 'escapedCount' => 1, 'errorCount' => 0, 'timeOutCount' => 0, 'skippedCount' => 0, 'ignoredCount' => 0, 'msi' => 33.33, 'mutationCodeCoverage' => 66.67, 'coveredCodeMsi' => 50.0],
            'killed' => [$mutant('Plus', 'killed')],
            'escaped' => [$mutant('Plus', 'escaped')],
            'uncovered' => [$mutant('Minus', 'uncovered')],
        ];
    }
}


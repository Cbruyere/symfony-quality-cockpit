<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\PHPUnit;

final class CoverageReportReader
{
    /**
     * @return array{
     *     available: bool,
     *     generatedAt: \DateTimeImmutable|null,
     *     summary: array{
     *         lines: array{percent: string, ratio: string, level: string},
     *         methods: array{percent: string, ratio: string, level: string},
     *         classes: array{percent: string, ratio: string, level: string}
     *     }|null,
     *     incompleteFiles: list<array{
     *         className: string,
     *         shortName: string,
     *         namespace: string,
     *         sourcePath: string,
     *         reportPath: string,
     *         coveragePercent: float,
     *         lines: array{percent: string, ratio: string, level: string},
     *         methods: array{percent: string, ratio: string, level: string},
     *         classes: array{percent: string, ratio: string, level: string}
     *     }>,
     *     completeFiles: list<array{
     *         className: string,
     *         shortName: string,
     *         namespace: string,
     *         sourcePath: string,
     *         reportPath: string,
     *         coveragePercent: float,
     *         lines: array{percent: string, ratio: string, level: string},
     *         methods: array{percent: string, ratio: string, level: string},
     *         classes: array{percent: string, ratio: string, level: string}
     *     }>
     * }
     */
    public function read(string $reportDirectory): array
    {
        $reportIndex = $reportDirectory . '/index.html';

        if (!is_file($reportIndex)) {
            return [
                'available' => false,
                'generatedAt' => null,
                'summary' => null,
                'incompleteFiles' => [],
                'completeFiles' => [],
            ];
        }

        $files = $this->readFiles($reportDirectory);

        return [
            'available' => true,
            'generatedAt' => (new \DateTimeImmutable())->setTimestamp((int) filemtime($reportIndex)),
            'summary' => $this->readSummary($reportIndex),
            'incompleteFiles' => array_values(array_filter(
                $files,
                static fn (array $file): bool => $file['coveragePercent'] < 100.0,
            )),
            'completeFiles' => array_values(array_filter(
                $files,
                static fn (array $file): bool => 100.0 === $file['coveragePercent'],
            )),
        ];
    }

    /**
     * @return array{
     *     className: string,
     *     shortName: string,
     *     namespace: string,
     *     sourcePath: string,
     *     reportPath: string,
     *     coveragePercent: float,
     *     lines: array{percent: string, ratio: string, level: string},
     *     methods: array{percent: string, ratio: string, level: string},
     *     classes: array{percent: string, ratio: string, level: string},
     *     methodsList: list<array{
     *         name: string,
     *         signature: string,
     *         line: int,
     *         crap: string,
     *         lines: array{percent: string, ratio: string, level: string},
     *         methods: array{percent: string, ratio: string, level: string}
     *     }>,
     *     sourceLines: list<array{number: int, code: string, level: string}>
     * }|null
     */
    public function readDetail(string $reportDirectory, string $reportPath): ?array
    {
        $htmlFile = $this->getReportHtmlFile($reportDirectory, $reportPath);

        if (null === $htmlFile) {
            return null;
        }

        $classRow = $this->getClassTableRow($htmlFile);

        if (!$classRow instanceof \DOMElement) {
            return null;
        }

        $relativeReportPath = str_replace(\DIRECTORY_SEPARATOR, '/', $reportPath);
        $className = $this->readClassName($classRow, $relativeReportPath);
        $metrics = $this->readMetrics($classRow);

        return [
            'className' => $className,
            'shortName' => $this->readShortClassName($className),
            'namespace' => $this->readNamespace($className),
            'sourcePath' => 'src/' . substr($relativeReportPath, 0, -5),
            'reportPath' => $relativeReportPath,
            'coveragePercent' => $this->readCoveragePercent($metrics['lines']['percent']),
            'lines' => $metrics['lines'],
            'methods' => $metrics['methods'],
            'classes' => $metrics['classes'],
            'methodsList' => $this->readMethodRows($htmlFile),
            'sourceLines' => $this->readSourceLines($htmlFile),
        ];
    }

    /**
     * @return array{
     *     lines: array{percent: string, ratio: string, level: string},
     *     methods: array{percent: string, ratio: string, level: string},
     *     classes: array{percent: string, ratio: string, level: string}
     * }
     */
    private function readSummary(string $reportIndex): array
    {
        $row = $this->getFirstTableRow($reportIndex);

        if (!$row instanceof \DOMElement) {
            return $this->emptyMetrics();
        }

        return $this->readMetrics($row);
    }

    /**
     * @return list<array{
     *     className: string,
     *     shortName: string,
     *     namespace: string,
     *     sourcePath: string,
     *     reportPath: string,
     *     coveragePercent: float,
     *     lines: array{percent: string, ratio: string, level: string},
     *     methods: array{percent: string, ratio: string, level: string},
     *     classes: array{percent: string, ratio: string, level: string}
     * }>
     */
    private function readFiles(string $reportDirectory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($reportDirectory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (!str_ends_with($path, '.php.html')) {
                continue;
            }

            $relativeReportPath = ltrim(str_replace($reportDirectory, '', $path), \DIRECTORY_SEPARATOR);
            $classRow = $this->getClassTableRow($path);

            if (!$classRow instanceof \DOMElement) {
                continue;
            }

            $className = $this->readClassName($classRow, $relativeReportPath);
            $shortName = $this->readShortClassName($className);
            $metrics = $this->readMetrics($classRow);

            $files[] = [
                'className' => $className,
                'shortName' => $shortName,
                'namespace' => $this->readNamespace($className),
                'sourcePath' => 'src/' . substr($relativeReportPath, 0, -5),
                'reportPath' => str_replace(\DIRECTORY_SEPARATOR, '/', $relativeReportPath),
                'coveragePercent' => $this->readCoveragePercent($metrics['lines']['percent']),
                'lines' => $metrics['lines'],
                'methods' => $metrics['methods'],
                'classes' => $metrics['classes'],
            ];
        }

        usort(
            $files,
            static fn (array $first, array $second): int => $second['coveragePercent'] <=> $first['coveragePercent']
                ?: $first['className'] <=> $second['className'],
        );

        return $files;
    }

    private function getFirstTableRow(string $htmlFile): ?\DOMElement
    {
        $xpath = $this->createXPath($htmlFile);
        $rows = $xpath?->query('//tbody/tr');
        $row = $rows?->item(0);

        return $row instanceof \DOMElement ? $row : null;
    }

    private function getClassTableRow(string $htmlFile): ?\DOMElement
    {
        $xpath = $this->createXPath($htmlFile);
        $rows = $xpath?->query('//tbody/tr[td[1]//abbr]');
        $row = $rows?->item(0);

        return $row instanceof \DOMElement ? $row : null;
    }

    /**
     * @return list<array{
     *     name: string,
     *     signature: string,
     *     line: int,
     *     crap: string,
     *     lines: array{percent: string, ratio: string, level: string},
     *     methods: array{percent: string, ratio: string, level: string}
     * }>
     */
    private function readMethodRows(string $htmlFile): array
    {
        $xpath = $this->createXPath($htmlFile);
        $rows = $xpath?->query('//tbody/tr[td[1]/a/abbr]');

        if (!$rows instanceof \DOMNodeList) {
            return [];
        }

        $methods = [];

        foreach ($rows as $row) {
            if (!$row instanceof \DOMElement) {
                continue;
            }

            $abbr = $row->getElementsByTagName('abbr')->item(0);
            $link = $row->getElementsByTagName('a')->item(0);

            if (!$abbr instanceof \DOMElement || !$link instanceof \DOMElement) {
                continue;
            }

            $metrics = $this->readMetrics($row);

            $methods[] = [
                'name' => $this->normalizeText($abbr->textContent),
                'signature' => $abbr->getAttribute('title'),
                'line' => (int) ltrim($link->getAttribute('href'), '#'),
                'crap' => $this->readCrapScore($row),
                'lines' => $metrics['lines'],
                'methods' => $metrics['methods'],
            ];
        }

        return $methods;
    }

    /**
     * @return list<array{number: int, code: string, level: string}>
     */
    private function readSourceLines(string $htmlFile): array
    {
        $xpath = $this->createXPath($htmlFile);
        $rows = $xpath?->query('//table[@id="code"]/tbody/tr');

        if (!$rows instanceof \DOMNodeList) {
            return [];
        }

        $sourceLines = [];

        foreach ($rows as $row) {
            if (!$row instanceof \DOMElement) {
                continue;
            }

            $lineNumber = $row->getElementsByTagName('a')->item(0);
            $cells = $row->getElementsByTagName('td');
            $codeCell = $cells->item(1);

            if (!$lineNumber instanceof \DOMElement || !$codeCell instanceof \DOMElement) {
                continue;
            }

            $sourceLines[] = [
                'number' => (int) $lineNumber->textContent,
                'code' => str_replace("\u{00A0}", ' ', $codeCell->textContent),
                'level' => $this->readLineLevel($row->getAttribute('class')),
            ];
        }

        return $sourceLines;
    }

    private function createXPath(string $htmlFile): ?\DOMXPath
    {
        $document = new \DOMDocument();

        if (!@$document->loadHTMLFile($htmlFile)) {
            return null;
        }

        return new \DOMXPath($document);
    }

    private function getReportHtmlFile(string $reportDirectory, string $reportPath): ?string
    {
        if (!str_ends_with($reportPath, '.php.html')) {
            return null;
        }

        $reportRoot = realpath($reportDirectory);

        if (false === $reportRoot) {
            return null;
        }

        $htmlFile = realpath($reportRoot . '/' . $reportPath);

        if (
            false === $htmlFile
            || !str_starts_with($htmlFile, $reportRoot . \DIRECTORY_SEPARATOR)
            || !is_file($htmlFile)
        ) {
            return null;
        }

        return $htmlFile;
    }

    /**
     * @return array{
     *     lines: array{percent: string, ratio: string, level: string},
     *     methods: array{percent: string, ratio: string, level: string},
     *     classes: array{percent: string, ratio: string, level: string}
     * }
     */
    private function readMetrics(\DOMElement $row): array
    {
        $cells = $row->getElementsByTagName('td');

        return [
            'lines' => $this->readMetric($cells, percentIndex: 2, ratioIndex: 3),
            'methods' => $this->readMetric($cells, percentIndex: 5, ratioIndex: 6),
            'classes' => $this->readMetric($cells, percentIndex: 8, ratioIndex: 9),
        ];
    }

    /**
     * @return array{percent: string, ratio: string, level: string}
     */
    /**
     * @param \DOMNodeList<\DOMElement> $cells
     *
     * @return array{percent: string, ratio: string, level: string}
     */
    private function readMetric(\DOMNodeList $cells, int $percentIndex, int $ratioIndex): array
    {
        $percent = $this->normalizeText(
            $cells->count() > $percentIndex ? $cells->item($percentIndex)->textContent : 'n/a',
        );

        return [
            'percent' => $percent,
            'ratio' => $this->normalizeText(
                $cells->count() > $ratioIndex ? $cells->item($ratioIndex)->textContent : '0 / 0',
            ),
            'level' => $this->readCoverageLevel($percent),
        ];
    }

    /**
     * @return array{
     *     lines: array{percent: string, ratio: string, level: string},
     *     methods: array{percent: string, ratio: string, level: string},
     *     classes: array{percent: string, ratio: string, level: string}
     * }
     */
    private function emptyMetrics(): array
    {
        return [
            'lines' => ['percent' => 'n/a', 'ratio' => '0 / 0', 'level' => 'neutral'],
            'methods' => ['percent' => 'n/a', 'ratio' => '0 / 0', 'level' => 'neutral'],
            'classes' => ['percent' => 'n/a', 'ratio' => '0 / 0', 'level' => 'neutral'],
        ];
    }

    private function readClassName(\DOMElement $row, string $relativeReportPath): string
    {
        $abbr = $row->getElementsByTagName('abbr')->item(0);
        $title = $abbr instanceof \DOMElement ? $abbr->getAttribute('title') : '';

        if ('' !== $title) {
            return $title;
        }

        return str_replace(['/', '.php.html'], ['\\', ''], $relativeReportPath);
    }

    private function readShortClassName(string $className): string
    {
        $parts = explode('\\', $className);

        return (string) end($parts);
    }

    private function readNamespace(string $className): string
    {
        $position = strrpos($className, '\\');

        if (false === $position) {
            return '';
        }

        return substr($className, 0, $position);
    }

    private function readCoverageLevel(string $percent): string
    {
        if ('n/a' === $percent) {
            return 'neutral';
        }

        $value = $this->readCoveragePercent($percent);

        return match (true) {
            $value >= 90.0 => 'success',
            $value >= 50.0 => 'warning',
            default => 'danger',
        };
    }

    private function readCoveragePercent(string $percent): float
    {
        if ('n/a' === $percent) {
            return 0.0;
        }

        return (float) rtrim($percent, '%');
    }

    private function readCrapScore(\DOMElement $row): string
    {
        $cells = $row->getElementsByTagName('td');

        if ($cells->count() <= 7) {
            return 'n/a';
        }

        return $this->normalizeText($cells->item(7)->textContent);
    }

    private function readLineLevel(string $class): string
    {
        if (str_contains($class, 'danger')) {
            return 'danger';
        }

        if (str_contains($class, 'covered-by-')) {
            return 'success';
        }

        return 'neutral';
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}

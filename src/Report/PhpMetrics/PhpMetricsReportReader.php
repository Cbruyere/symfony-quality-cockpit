<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Report\PhpMetrics;

final class PhpMetricsReportReader
{
    /**
     * @var list<string>
     */
    private const JSON_REPORT_FILES = [
        'report.json',
        'phpmetrics.json',
        'phpmetrics-report.json',
        'metrics.json',
    ];

    /**
     * @var list<string>
     */
    private const XML_REPORT_FILES = [
        'report.xml',
        'phpmetrics.xml',
        'phpmetrics-report.xml',
        'metrics.xml',
    ];

    public function read(string $reportDirectory): PhpMetricsReport
    {
        $classes = $this->readClasses($reportDirectory);

        if ([] === $classes) {
            return PhpMetricsReport::unavailable();
        }

        $indexPath = is_file($reportDirectory . '/index.html') ? 'index.html' : null;
        $criticalClasses = array_values(array_filter(
            $classes,
            static fn (PhpMetricsClassMetric $classMetric): bool => $classMetric->critical,
        ));
        $topComplexClasses = $classes;
        usort(
            $topComplexClasses,
            static fn (PhpMetricsClassMetric $first, PhpMetricsClassMetric $second): int => ($second->cyclomaticComplexity->value ?? 0.0) <=> ($first->cyclomaticComplexity->value ?? 0.0)
                ?: ($second->maxMethodComplexity->value ?? 0.0) <=> ($first->maxMethodComplexity->value ?? 0.0)
                ?: $first->name <=> $second->name,
        );

        return new PhpMetricsReport(
            available: true,
            generatedAt: $this->readGeneratedAt($reportDirectory),
            htmlIndexPath: $indexPath,
            totalClasses: count($classes),
            criticalClassesCount: count($criticalClasses),
            averageMaintainabilityIndex: $this->averageMetric('Maintainability Index', $classes, 'maintainabilityIndex', 'maintainability'),
            averageCyclomaticComplexity: $this->averageMetric('Cyclomatic Complexity', $classes, 'cyclomaticComplexity', 'complexity'),
            averageCoupling: $this->averageMetric('Coupling', $classes, 'coupling', 'coupling'),
            averageLackOfCohesion: $this->averageMetric('LCOM', $classes, 'lackOfCohesion', 'cohesion'),
            averageHalsteadVolume: $this->averageMetric('Halstead Volume', $classes, 'halsteadVolume', 'volume'),
            classes: $classes,
            criticalClasses: $criticalClasses,
            topComplexClasses: array_slice($topComplexClasses, 0, 10),
            dependencies: $this->readDependencies($classes),
        );
    }

    public function readClassDetail(string $reportDirectory, string $id): ?PhpMetricsClassMetric
    {
        foreach ($this->read($reportDirectory)->classes as $classMetric) {
            if ($classMetric->id === $id) {
                return $classMetric;
            }
        }

        return null;
    }

    /**
     * @return list<PhpMetricsClassMetric>
     */
    private function readClasses(string $reportDirectory): array
    {
        $payload = $this->readJsonPayload($reportDirectory);

        if (null !== $payload) {
            return $this->createClassMetrics($this->extractClassRows($payload));
        }

        $xmlRows = $this->readXmlRows($reportDirectory);

        if ([] !== $xmlRows) {
            return $this->createClassMetrics($xmlRows);
        }

        $classesJs = $this->readClassesJsPayload($reportDirectory);

        if (null !== $classesJs) {
            return $this->createClassMetrics($this->extractClassRows($classesJs));
        }

        return [];
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
     * @return array<string, mixed>|list<mixed>|null
     */
    private function readClassesJsPayload(string $reportDirectory): array|null
    {
        $path = $reportDirectory . '/js/classes.js';

        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if (false === $content || 1 !== preg_match('/var\s+classes\s*=\s*(\[.*\])\s*;/s', $content, $matches)) {
            return null;
        }

        $payload = json_decode($matches[1], true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function extractClassRows(array $payload): array
    {
        if (array_is_list($payload)) {
            return $this->filterRows($payload);
        }

        foreach (['classes', 'classMetrics', 'metrics', 'files'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->extractClassRows($payload[$key]);
            }
        }

        return $this->filterRows(array_values($payload));
    }

    /**
     * @param list<mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function filterRows(array $rows): array
    {
        $classRows = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                $classRows[] = $row;
            }
        }

        return $classRows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readXmlRows(string $reportDirectory): array
    {
        foreach (self::XML_REPORT_FILES as $fileName) {
            $path = $reportDirectory . '/' . $fileName;

            if (!is_file($path)) {
                continue;
            }

            $document = new \DOMDocument();

            if (!@$document->load($path)) {
                continue;
            }

            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query('//*[local-name() = "class" or local-name() = "file"]');

            if (!$nodes instanceof \DOMNodeList) {
                continue;
            }

            $rows = [];

            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }

                $name = $node->getAttribute('name') ?: $node->getAttribute('class');

                if ('' === $name) {
                    continue;
                }

                $row = ['name' => $name];

                foreach ($node->attributes ?? [] as $attribute) {
                    $row[$attribute->nodeName] = $attribute->nodeValue;
                }

                foreach ($xpath->query('.//*[local-name() = "metric"]', $node) ?: [] as $metricNode) {
                    if (!$metricNode instanceof \DOMElement) {
                        continue;
                    }

                    $metricName = $metricNode->getAttribute('name');
                    $metricValue = $metricNode->getAttribute('value');

                    if ('' !== $metricName) {
                        $row[$metricName] = $metricValue;
                    }
                }

                $rows[] = $row;
            }

            return $rows;
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<PhpMetricsClassMetric>
     */
    private function createClassMetrics(array $rows): array
    {
        $classes = [];

        foreach ($rows as $row) {
            $name = $this->readString($row, 'name');

            if ('' === $name || $this->readBool($row, 'interface')) {
                continue;
            }

            if (!$this->isClassMetricRow($row)) {
                continue;
            }

            $methods = $this->readMethods($row);
            $couplingValue = $this->readTotalCoupling($row);
            $methodMax = $this->readFloat($row, 'ccnMethodMax') ?? $this->readMaxMethodComplexity($methods);
            $htmlReportPath = $this->readString($row, 'htmlReportPath') ?: 'index.html';
            $maintainability = $this->createMetric('Maintainability Index', $this->readFloat($row, 'mi'), 'maintainability');
            $complexity = $this->createMetric('Cyclomatic Complexity', $this->readFloat($row, 'ccn'), 'complexity');
            $lackOfCohesion = $this->createMetric('LCOM', $this->readFloat($row, 'lcom'), 'cohesion');
            $coupling = $this->createMetric('Coupling', $couplingValue, 'coupling');

            $classes[] = new PhpMetricsClassMetric(
                id: $this->createId($name),
                name: $name,
                shortName: $this->readShortName($name),
                namespace: $this->readNamespace($name),
                maintainabilityIndex: $maintainability,
                cyclomaticComplexity: $complexity,
                maxMethodComplexity: $this->createMetric('Max Method Complexity', $methodMax, 'complexity'),
                coupling: $coupling,
                afferentCoupling: $this->createMetric('Afferent Coupling', $this->readFloat($row, 'afferentCoupling'), 'coupling'),
                efferentCoupling: $this->createMetric('Efferent Coupling', $this->readFloat($row, 'efferentCoupling'), 'coupling'),
                lackOfCohesion: $lackOfCohesion,
                halsteadVolume: $this->createMetric('Halstead Volume', $this->readFloat($row, 'volume'), 'volume'),
                methodsCount: (int) ($this->readFloat($row, 'nbMethods') ?? count($methods)),
                methodsIncludingGettersSettersCount: (int) ($this->readFloat($row, 'nbMethodsIncludingGettersSetters') ?? count($methods)),
                publicMethodsCount: (int) ($this->readFloat($row, 'nbMethodsPublic') ?? $this->countMethodsByVisibility($methods, 'public')),
                privateMethodsCount: (int) ($this->readFloat($row, 'nbMethodsPrivate') ?? $this->countMethodsByVisibility($methods, 'private')),
                getterMethodsCount: (int) ($this->readFloat($row, 'nbMethodsGetter') ?? $this->countMethodsByRole($methods, 'getter')),
                setterMethodsCount: (int) ($this->readFloat($row, 'nbMethodsSetters') ?? $this->countMethodsByRole($methods, 'setter')),
                weightedMethodCount: (int) ($this->readFloat($row, 'wmc') ?? 0.0),
                critical: $this->isCritical($maintainability, $complexity, $coupling, $lackOfCohesion),
                htmlReportPath: $htmlReportPath,
                methods: $methods,
                dependencies: $this->readClassDependencies($name, $row),
            );
        }

        usort(
            $classes,
            static fn (PhpMetricsClassMetric $first, PhpMetricsClassMetric $second): int => $first->name <=> $second->name,
        );

        return $classes;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<PhpMetricsMethodMetric>
     */
    private function readMethods(array $row): array
    {
        if (!isset($row['methods']) || !is_array($row['methods'])) {
            return [];
        }

        $methods = [];

        foreach ($row['methods'] as $method) {
            if (!is_array($method)) {
                continue;
            }

            $name = $this->readString($method, 'name');

            if ('' === $name) {
                continue;
            }

            $complexity = $this->readFloat($method, 'ccn')
                ?? $this->readFloat($method, 'cyclomaticComplexity')
                ?? $this->readFloat($method, 'complexity');

            $methods[] = new PhpMetricsMethodMetric(
                name: $name,
                role: $this->readMethodRole($method),
                visibility: $this->readMethodVisibility($method),
                cyclomaticComplexity: $complexity,
                level: $this->readLevel($complexity, 'complexity'),
            );
        }

        usort(
            $methods,
            static fn (PhpMetricsMethodMetric $first, PhpMetricsMethodMetric $second): int => ($second->cyclomaticComplexity ?? 0.0) <=> ($first->cyclomaticComplexity ?? 0.0)
                ?: $first->name <=> $second->name,
        );

        return $methods;
    }

    /**
     * @param list<PhpMetricsMethodMetric> $methods
     */
    private function readMaxMethodComplexity(array $methods): float|null
    {
        $values = array_filter(
            array_map(
                static fn (PhpMetricsMethodMetric $method): float|null => $method->cyclomaticComplexity,
                $methods,
            ),
            static fn (float|null $value): bool => null !== $value,
        );

        return [] === $values ? null : max($values);
    }

    /**
     * @param list<PhpMetricsMethodMetric> $methods
     */
    private function countMethodsByVisibility(array $methods, string $visibility): int
    {
        return count(array_filter(
            $methods,
            static fn (PhpMetricsMethodMetric $method): bool => $method->visibility === $visibility,
        ));
    }

    /**
     * @param list<PhpMetricsMethodMetric> $methods
     */
    private function countMethodsByRole(array $methods, string $role): int
    {
        return count(array_filter(
            $methods,
            static fn (PhpMetricsMethodMetric $method): bool => $method->role === $role,
        ));
    }

    /**
     * @param array<string, mixed> $method
     */
    private function readMethodRole(array $method): string
    {
        $role = $this->readString($method, 'role');

        return '' === $role ? 'standard' : $role;
    }

    /**
     * @param array<string, mixed> $method
     */
    private function readMethodVisibility(array $method): string
    {
        if ($this->readBool($method, 'private')) {
            return 'private';
        }

        if ($this->readBool($method, 'public')) {
            return 'public';
        }

        if ($this->readBool($method, 'protected')) {
            return 'protected';
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<PhpMetricsDependency>
     */
    private function readClassDependencies(string $className, array $row): array
    {
        if (!isset($row['externals']) || !is_array($row['externals'])) {
            return [];
        }

        $dependencies = [];

        foreach (array_unique($row['externals']) as $external) {
            if (is_string($external) && '' !== $external) {
                $dependencies[] = new PhpMetricsDependency($className, $external, 'external');
            }
        }

        return $dependencies;
    }

    /**
     * @param list<PhpMetricsClassMetric> $classes
     *
     * @return list<PhpMetricsDependency>
     */
    private function readDependencies(array $classes): array
    {
        $dependencies = [];

        foreach ($classes as $classMetric) {
            foreach ($classMetric->dependencies as $dependency) {
                $dependencies[] = $dependency;
            }
        }

        return $dependencies;
    }

    /**
     * @param list<PhpMetricsClassMetric> $classes
     */
    private function averageMetric(string $label, array $classes, string $property, string $kind): PhpMetricsMetric
    {
        $values = [];

        foreach ($classes as $classMetric) {
            $metric = match ($property) {
                'maintainabilityIndex' => $classMetric->maintainabilityIndex,
                'cyclomaticComplexity' => $classMetric->cyclomaticComplexity,
                'coupling' => $classMetric->coupling,
                'lackOfCohesion' => $classMetric->lackOfCohesion,
                'halsteadVolume' => $classMetric->halsteadVolume,
                default => null,
            };

            if ($metric instanceof PhpMetricsMetric && null !== $metric->value) {
                $values[] = $metric->value;
            }
        }

        if ([] === $values) {
            return new PhpMetricsMetric($label, null, 'n/a', 'neutral');
        }

        return $this->createMetric($label, array_sum($values) / count($values), $kind);
    }

    private function createMetric(string $label, float|null $value, string $kind): PhpMetricsMetric
    {
        return new PhpMetricsMetric(
            label: $label,
            value: $value,
            formattedValue: null === $value ? 'n/a' : $this->formatNumber($value),
            level: $this->readLevel($value, $kind),
        );
    }

    private function readLevel(float|null $value, string $kind): string
    {
        if (null === $value) {
            return 'neutral';
        }

        return match ($kind) {
            'maintainability' => match (true) {
                $value >= 85.0 => 'success',
                $value >= 65.0 => 'warning',
                default => 'danger',
            },
            'complexity' => match (true) {
                $value <= 10.0 => 'success',
                $value <= 20.0 => 'warning',
                default => 'danger',
            },
            'coupling' => match (true) {
                $value <= 5.0 => 'success',
                $value <= 10.0 => 'warning',
                default => 'danger',
            },
            'cohesion' => match (true) {
                $value <= 1.0 => 'success',
                $value <= 3.0 => 'warning',
                default => 'danger',
            },
            'volume' => match (true) {
                $value <= 800.0 => 'success',
                $value <= 2000.0 => 'warning',
                default => 'danger',
            },
            default => 'neutral',
        };
    }

    private function isCritical(PhpMetricsMetric ...$metrics): bool
    {
        foreach ($metrics as $metric) {
            if ('danger' === $metric->level) {
                return true;
            }
        }

        return false;
    }

    private function createId(string $className): string
    {
        return rtrim(strtr(base64_encode($className), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function readTotalCoupling(array $row): float|null
    {
        $coupling = $this->readFloat($row, 'coupling');

        if (null !== $coupling) {
            return $coupling;
        }

        $afferent = $this->readFloat($row, 'afferentCoupling');
        $efferent = $this->readFloat($row, 'efferentCoupling');

        if (null === $afferent && null === $efferent) {
            return null;
        }

        return ($afferent ?? 0.0) + ($efferent ?? 0.0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isClassMetricRow(array $row): bool
    {
        $type = $this->readString($row, '_type');

        if ('' !== $type) {
            return str_ends_with($type, '\\ClassMetric');
        }

        return isset($row['ccn'], $row['mi'])
            && '' !== $this->readString($row, 'name');
    }

    private function readGeneratedAt(string $reportDirectory): \DateTimeImmutable|null
    {
        foreach (['report.json', 'phpmetrics.json', 'index.html', 'js/classes.js'] as $fileName) {
            $path = $reportDirectory . '/' . $fileName;

            if (is_file($path)) {
                return (new \DateTimeImmutable())->setTimestamp((int) filemtime($path));
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function readString(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
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

    /**
     * @param array<string, mixed> $row
     */
    private function readBool(array $row, string $key): bool
    {
        $value = $row[$key] ?? false;

        return true === $value || 'true' === $value || '1' === $value || 1 === $value;
    }

    private function readShortName(string $className): string
    {
        $parts = explode('\\', $className);

        return (string) end($parts);
    }

    private function readNamespace(string $className): string
    {
        $position = strrpos($className, '\\');

        return false === $position ? '' : substr($className, 0, $position);
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ' '), '0'), '.');
    }
}


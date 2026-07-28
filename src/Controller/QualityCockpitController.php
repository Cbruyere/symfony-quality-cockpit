<?php

declare(strict_types=1);

namespace Chrisdev\QualityCockpit\Controller;

use Chrisdev\QualityCockpit\Report\ComposerAudit\ComposerAuditReportReader;
use Chrisdev\QualityCockpit\Report\Deptrac\DeptracReportReader;
use Chrisdev\QualityCockpit\Report\Infection\InfectionReportReader;
use Chrisdev\QualityCockpit\Report\PhpMetrics\PhpMetricsReportReader;
use Chrisdev\QualityCockpit\Report\PhpMetrics\PhpMetricsSectionDetailFactory;
use Chrisdev\QualityCockpit\Health\QualityHealthEvaluator;
use Chrisdev\QualityCockpit\Report\PHPUnit\CoverageReportReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class TestResultsController extends AbstractController
{
    /** @param array{base_directory: string} $reports */
    public function __construct(
        #[Autowire('%quality_cockpit.reports%')]
        private readonly array $reports,
        private readonly CoverageReportReader $coverageReportReader,
        private readonly PhpMetricsReportReader $phpMetricsReportReader,
        private readonly PhpMetricsSectionDetailFactory $phpMetricsSectionDetailFactory,
        private readonly DeptracReportReader $deptracReportReader,
        private readonly ComposerAuditReportReader $composerAuditReportReader,
        private readonly InfectionReportReader $infectionReportReader,
        private readonly QualityHealthEvaluator $qualityHealthEvaluator,
    ) {
    }

    #[Route('', name: 'quality_cockpit_dashboard')]
    public function index(): Response
    {
        $report = $this->coverageReportReader->read($this->getCoverageReportDirectory());
        $phpMetricsReport = $this->phpMetricsReportReader->read($this->getPhpMetricsReportDirectory());
        $deptracReport = $this->deptracReportReader->read($this->getDeptracReportDirectory());
        $composerAuditReport = $this->composerAuditReportReader->read($this->getComposerAuditReportDirectory());
        $infectionReport = $this->infectionReportReader->read($this->getInfectionReportDirectory());
        $health = $this->qualityHealthEvaluator->evaluate($report, $phpMetricsReport, $deptracReport, $composerAuditReport, $infectionReport);

        return $this->render('@QualityCockpit/dashboard/tests.html.twig', [
            'report' => $report,
            'phpMetricsReport' => $phpMetricsReport,
            'deptracReport' => $deptracReport,
            'composerAuditReport' => $composerAuditReport,
            'infectionReport' => $infectionReport,
            'qualityHealth' => $health,
        ]);
    }

    #[Route('/details/{path}', name: 'quality_cockpit_test_results_detail', requirements: ['path' => '.+'])]
    public function detail(string $path): Response
    {
        $detail = $this->coverageReportReader->readDetail($this->getCoverageReportDirectory(), $path);

        if (null === $detail) {
            throw new NotFoundHttpException('Test coverage report detail was not found.');
        }

        return $this->render('@QualityCockpit/report/detail.html.twig', [
            'detail' => $detail,
        ]);
    }

    #[Route('/phpmetrics/classes/{id}', name: 'quality_cockpit_phpmetrics_detail')]
    public function phpMetricsDetail(string $id): Response
    {
        $detail = $this->phpMetricsReportReader->readClassDetail($this->getPhpMetricsReportDirectory(), $id);

        if (null === $detail) {
            throw new NotFoundHttpException('PhpMetrics class detail was not found.');
        }

        return $this->render('@QualityCockpit/report/phpmetrics_detail.html.twig', [
            'detail' => $detail,
        ]);
    }

    #[Route('/phpmetrics/sections/{section}', name: 'quality_cockpit_phpmetrics_section')]
    public function phpMetricsSection(string $section): Response
    {
        $report = $this->phpMetricsReportReader->read($this->getPhpMetricsReportDirectory());

        if (!$report->available) {
            throw new NotFoundHttpException('PhpMetrics report was not found.');
        }

        $detail = $this->phpMetricsSectionDetailFactory->create($section, $report);

        if (null === $detail) {
            throw new NotFoundHttpException('PhpMetrics section was not found.');
        }

        return $this->render('@QualityCockpit/report/phpmetrics_section.html.twig', [
            'detail' => $detail,
        ]);
    }

    #[Route('/deptrac/violations/{id}', name: 'quality_cockpit_deptrac_violation_detail')]
    public function deptracViolationDetail(string $id): Response
    {
        $detail = $this->deptracReportReader->readViolationDetail($this->getDeptracReportDirectory(), $id);

        if (null === $detail) {
            throw new NotFoundHttpException('Deptrac violation detail was not found.');
        }

        return $this->render('@QualityCockpit/report/deptrac_detail.html.twig', [
            'detail' => $detail,
        ]);
    }

    #[Route('/composer-audit/advisories/{id}', name: 'quality_cockpit_composer_audit_advisory_detail')]
    public function composerAuditAdvisoryDetail(string $id): Response
    {
        $detail = $this->composerAuditReportReader->readAdvisoryDetail($this->getComposerAuditReportDirectory(), $id);

        if (null === $detail) {
            throw new NotFoundHttpException('Composer audit advisory detail was not found.');
        }

        return $this->render('@QualityCockpit/report/composer_audit_detail.html.twig', [
            'detail' => $detail,
        ]);
    }

    #[Route('/infection/mutants/{id}', name: 'quality_cockpit_infection_mutant_detail')]
    public function infectionMutantDetail(string $id): Response
    {
        $report = $this->infectionReportReader->read($this->getInfectionReportDirectory());
        foreach ($report->mutants as $mutant) {
            if ($mutant->id === $id) {
                return $this->render('@QualityCockpit/report/infection_detail.html.twig', ['mutant' => $mutant]);
            }
        }
        throw new NotFoundHttpException('Infection mutant detail was not found.');
    }

    #[Route('/report/{path}', name: 'quality_cockpit_test_results_report', requirements: ['path' => '.+'])]
    public function report(string $path): BinaryFileResponse
    {
        $reportRoot = realpath($this->getCoverageReportDirectory());

        if (false === $reportRoot) {
            throw new NotFoundHttpException('Test coverage report directory was not found.');
        }

        $filePath = realpath($reportRoot.'/'.$path);

        if (
            false === $filePath
            || !str_starts_with($filePath, $reportRoot.\DIRECTORY_SEPARATOR)
            || !is_file($filePath)
        ) {
            throw new NotFoundHttpException('Test coverage report file was not found.');
        }

        return $this->createReportFileResponse($filePath);
    }

    #[Route('/phpmetrics/report/{path}', name: 'quality_cockpit_phpmetrics_report', requirements: ['path' => '.+'])]
    public function phpMetricsReport(string $path): BinaryFileResponse
    {
        $reportRoot = realpath($this->getPhpMetricsReportDirectory());

        if (false === $reportRoot) {
            throw new NotFoundHttpException('PhpMetrics report directory was not found.');
        }

        $filePath = realpath($reportRoot.'/'.$path);

        if (
            false === $filePath
            || !str_starts_with($filePath, $reportRoot.\DIRECTORY_SEPARATOR)
            || !is_file($filePath)
        ) {
            throw new NotFoundHttpException('PhpMetrics report file was not found.');
        }

        return $this->createReportFileResponse($filePath);
    }

    #[Route('/deptrac/report/{path}', name: 'quality_cockpit_deptrac_report', requirements: ['path' => '.+'])]
    public function deptracReport(string $path): BinaryFileResponse
    {
        $reportRoot = realpath($this->getDeptracReportDirectory());

        if (false === $reportRoot) {
            throw new NotFoundHttpException('Deptrac report directory was not found.');
        }

        $filePath = realpath($reportRoot.'/'.$path);

        if (
            false === $filePath
            || !str_starts_with($filePath, $reportRoot.\DIRECTORY_SEPARATOR)
            || !is_file($filePath)
        ) {
            throw new NotFoundHttpException('Deptrac report file was not found.');
        }

        return $this->createReportFileResponse($filePath);
    }

    #[Route('/composer-audit/report/{path}', name: 'quality_cockpit_composer_audit_report', requirements: ['path' => '.+'])]
    public function composerAuditReport(string $path): BinaryFileResponse
    {
        $reportRoot = realpath($this->getComposerAuditReportDirectory());

        if (false === $reportRoot) {
            throw new NotFoundHttpException('Composer audit report directory was not found.');
        }

        $filePath = realpath($reportRoot.'/'.$path);

        if (
            false === $filePath
            || !str_starts_with($filePath, $reportRoot.\DIRECTORY_SEPARATOR)
            || !is_file($filePath)
        ) {
            throw new NotFoundHttpException('Composer audit report file was not found.');
        }

        return $this->createReportFileResponse($filePath);
    }

    #[Route('/infection/report/{path}', name: 'quality_cockpit_infection_report', requirements: ['path' => '.+'])]
    public function infectionReport(string $path): BinaryFileResponse
    {
        $reportRoot = realpath($this->getInfectionReportDirectory());
        $filePath = false === $reportRoot ? false : realpath($reportRoot.'/'.$path);
        if (false === $filePath || !str_starts_with($filePath, $reportRoot.\DIRECTORY_SEPARATOR) || !is_file($filePath)) {
            throw new NotFoundHttpException('Infection report file was not found.');
        }

        return $this->createReportFileResponse($filePath);
    }

    private function createReportFileResponse(string $filePath): BinaryFileResponse
    {
        $response = new BinaryFileResponse($filePath);
        $extension = pathinfo($filePath, \PATHINFO_EXTENSION);

        $contentType = match ($extension) {
            'css' => 'text/css; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'md', 'log' => 'text/plain; charset=UTF-8',
            default => null,
        };

        if (null !== $contentType) {
            $response->headers->set('Content-Type', $contentType);
        }

        return $response;
    }

    private function getCoverageReportDirectory(): string
    {
        return $this->reports['base_directory'].'/tests/coverage-html';
    }

    private function getPhpMetricsReportDirectory(): string
    {
        return $this->reports['base_directory'].'/phpmetrics';
    }

    private function getDeptracReportDirectory(): string
    {
        return $this->reports['base_directory'].'/deptrac';
    }

    private function getComposerAuditReportDirectory(): string
    {
        return $this->reports['base_directory'].'/composer-audit';
    }

    private function getInfectionReportDirectory(): string
    {
        return $this->reports['base_directory'].'/infection';
    }
}

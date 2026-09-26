<?php

declare(strict_types=1);

namespace App\Controller\Equipment;

use App\Attribute\RequiresFeature;
use App\Entity\SchoolYear;
use App\Enum\Feature;
use App\Repository\EquipmentMovementRepository;
use App\Repository\SchoolYearRepository;
use App\Service\Equipment\EquipmentLossReport;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion > Matériel > Bilan annuel - what was lost over one school year, next to the year before.
 *
 * The year is the platform's own SchoolYear, and an incident belongs to the one its date falls in
 * (a reading of the journal, never a column). The figures are net of what was found or repaired
 * since - see App\Service\Equipment\EquipmentLossReport.
 */
#[IsGranted(new Expression(self::ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::Equipment)]
class ReportController extends AbstractController
{
    use EquipmentTrait;

    #[Route(path: '/equipment/report', name: 'app_equipment_report', methods: ['GET'])]
    public function index(Request $request, SchoolYearRepository $years, EquipmentMovementRepository $movements, EquipmentLossReport $report): Response
    {
        $schoolYears = $years->findAllActiveOrderedByMostRecent();
        $yearId = QueryValue::nullableInt($request, 'year');
        $year = null;
        foreach ($schoolYears as $candidate) {
            if ($candidate->getId() === $yearId) {
                $year = $candidate;
            }
        }
        $year ??= $years->findCurrentOrMostRecent();

        if (!$year instanceof SchoolYear) {
            return $this->render('equipment/report.html.twig', ['schoolYears' => [], 'year' => null]);
        }

        $previous = null;
        foreach ($schoolYears as $candidate) {
            if ($candidate->getStartDate() < $year->getStartDate() && (null === $previous || $candidate->getStartDate() > $previous->getStartDate())) {
                $previous = $candidate;
            }
        }

        return $this->render('equipment/report.html.twig', [
            'schoolYears' => $schoolYears,
            'year' => $year,
            'previous' => $previous,
            'report' => $this->buildFor($year, $movements, $report),
            'previousReport' => null !== $previous ? $this->buildFor($previous, $movements, $report) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFor(SchoolYear $year, EquipmentMovementRepository $movements, EquipmentLossReport $report): array
    {
        $start = ($year->getStartDate() ?? new \DateTimeImmutable())->setTime(0, 0);
        $end = ($year->getEndDate() ?? $start)->setTime(23, 59, 59);

        return $report->build($movements->findIncidentRows($start, $end), $start);
    }
}

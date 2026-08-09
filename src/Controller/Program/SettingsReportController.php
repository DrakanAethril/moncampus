<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Entity\Program;
use App\Entity\ProgramReport;
use App\Entity\User;
use App\Form\ProgramReportType;
use App\Repository\ProgramReportRepository;
use App\Repository\ProgramRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Formation > Paramétrage, onglet « Comptes rendus » : les ProgramReport et leur impression.
 *
 * Split out of the former ProgramSettingsController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsReportController extends AbstractController
{
    use ProgramSettingsTabTrait;

    #[Route(path: '/programs/{id}/settings/reports', name: 'app_program_settings_reports')]
    public function reportsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'reports');
    }

    #[Route(path: '/programs/{id}/settings/reports/new', name: 'app_program_settings_reports_new')]
    #[Route(path: '/programs/{id}/settings/reports/{reportId}/edit', name: 'app_program_settings_reports_edit')]
    public function reportForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ProgramReportRepository $reportRepository, ?int $reportId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $report = null !== $reportId ? $this->findReportOrNotFound($reportRepository, $program, $reportId) : null;
        $isEdit = null !== $report;

        // Must be resolved and set on an existing $report before handleRequest()/isValid() runs -
        // ProgramReport::$referee carries an Assert\NotNull, so setting it only on success would
        // make the form permanently invalid for the edit case (referee is null right up to the
        // point isValid() runs). Guarded to POST only - on GET there's nothing submitted yet, and
        // resolving an empty "referee" would otherwise wipe the existing value right before
        // rendering it. For the new-entity case there's no $report yet to set it on, so it's
        // passed through as a form option instead and consumed by ProgramReportType's own
        // empty_data - see that class's docblock. Same convention as
        // LaptopController::resolveActiveBorrower().
        $referee = $isEdit ? $report->getReferee() : null;
        if ($request->isMethod('POST')) {
            $referee = $this->resolveProgramTeacher($program, $request->request->get('referee'));
            $report?->setReferee($referee);
        }

        $form = $this->createForm(ProgramReportType::class, $report, ['program' => $program, 'referee' => $referee]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'reportUpdatedFlashMessage' : 'reportCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_reports', ['id' => $program->getId()]);
        }

        return $this->render('program/report_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/reports/{reportId}/deactivate', name: 'app_program_settings_reports_deactivate', methods: ['POST'])]
    public function deactivateReport(int $id, int $reportId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ProgramReportRepository $reportRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $report = $this->findReportOrNotFound($reportRepository, $program, $reportId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $report->setInactiveDate(new \DateTimeImmutable());
        $report->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // Backs the referee ajax tom-select field in report_new.html.twig - only the program's own
    // teachers are eligible, same convention as
    // ProgramTimetableSettingsController::teachersSearch().
    #[Route(path: '/programs/{id}/settings/reports/referees-search', name: 'app_program_settings_reports_referees_search')]
    public function refereesSearch(int $id, Request $request, ProgramRepository $repository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $limit = 20;
        $query = mb_strtolower((string) $request->query->get('q', ''));

        $candidates = array_values(array_filter(
            $program->getTeachers()->toArray(),
            static fn (User $user): bool => '' === $query || str_contains(mb_strtolower($user->getDisplayName() ?? $user->getUsername()), $query),
        ));

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], \array_slice($candidates, 0, $limit)),
            'pagination' => ['more' => \count($candidates) > $limit],
        ]);
    }

    #[Route(path: '/programs/{id}/settings/reports/data', name: 'app_program_settings_reports_data')]
    public function reportsData(int $id, Request $request, ProgramRepository $repository, ProgramReportRepository $reportRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

        $total = $reportRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $reportRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $reportRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (ProgramReport $report): array => [
                    'id' => $report->getId(),
                    'isInactive' => null !== $report->getInactiveDate(),
                    'title' => $report->getTitle(),
                    'day' => $report->getDay()->format('d/m/Y'),
                    'refereeName' => $this->userLabel($report->getReferee()),
                ],
                $rows,
            ),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/reports/{reportId}/print', name: 'app_program_settings_reports_print')]
    public function printReport(int $id, int $reportId, ProgramRepository $repository, ProgramReportRepository $reportRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $report = $this->findReportOrNotFound($reportRepository, $program, $reportId);

        return $this->render('program/report_print.html.twig', [
            'program' => $program,
            'report' => $report,
        ]);
    }

    private function findReportOrNotFound(ProgramReportRepository $repository, Program $program, int $reportId): ProgramReport
    {
        $report = $repository->find($reportId) ?? throw $this->createNotFoundException();

        if ($report->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $report;
    }
}

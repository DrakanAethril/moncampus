<?php

namespace App\Controller\Program;

use App\Entity\InternshipEvaluationPeriod;
use App\Form\InternshipEvaluationPeriodType;
use App\Repository\InternshipEvaluationPeriodRepository;
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
 * Formation > Livret de l'alternant, onglet « Périodes d'évaluation ».
 *
 * Split out of the former ProgramInternshipController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class InternshipEvaluationPeriodController extends AbstractController
{
    use ProgramInternshipTrait;

    #[Route(path: '/programs/{id}/internship/evaluation-periods', name: 'app_program_internship_evaluation_periods')]
    public function evaluationPeriodsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'evaluation_periods');
    }

    #[Route(path: '/programs/{id}/internship/evaluation-periods/new', name: 'app_program_internship_evaluation_periods_new')]
    #[Route(path: '/programs/{id}/internship/evaluation-periods/{evaluationPeriodId}/edit', name: 'app_program_internship_evaluation_periods_edit')]
    public function evaluationPeriodForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, ?int $evaluationPeriodId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $evaluationPeriod = null !== $evaluationPeriodId ? $this->findEvaluationPeriodOrNotFound($evaluationPeriodRepository, $program, $evaluationPeriodId) : null;
        $isEdit = null !== $evaluationPeriod;

        $form = $this->createForm(InternshipEvaluationPeriodType::class, $evaluationPeriod, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipEvaluationPeriodUpdatedFlashMessage' : 'internshipEvaluationPeriodCreatedFlashMessage');

            return $this->redirectToRoute('app_program_internship_evaluation_periods', ['id' => $program->getId()]);
        }

        return $this->render('program/internship_evaluation_period_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/evaluation-periods/{evaluationPeriodId}/deactivate', name: 'app_program_internship_evaluation_periods_deactivate', methods: ['POST'])]
    public function deactivateEvaluationPeriod(int $id, int $evaluationPeriodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $evaluationPeriod = $this->findEvaluationPeriodOrNotFound($evaluationPeriodRepository, $program, $evaluationPeriodId);
        $this->assertValidToken('program_internship_deactivate', $request);

        $evaluationPeriod->setInactiveDate(new \DateTimeImmutable());
        $evaluationPeriod->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/internship/evaluation-periods/data', name: 'app_program_internship_evaluation_periods_data')]
    public function evaluationPeriodsData(int $id, Request $request, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $evaluationPeriodRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $evaluationPeriodRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $evaluationPeriodRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (InternshipEvaluationPeriod $evaluationPeriod): array => [
                    'id' => $evaluationPeriod->getId(),
                    'isInactive' => null !== $evaluationPeriod->getInactiveDate(),
                    'name' => $evaluationPeriod->getName(),
                    'startDate' => $evaluationPeriod->getStartDate()?->format('d/m/Y') ?? '—',
                    'endDate' => $evaluationPeriod->getEndDate()?->format('d/m/Y') ?? '—',
                    'creationDate' => $evaluationPeriod->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $evaluationPeriod->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($evaluationPeriod->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($evaluationPeriod->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($evaluationPeriod->getLastUpdatedBy()),
                    'lastUpdatedDate' => $evaluationPeriod->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}

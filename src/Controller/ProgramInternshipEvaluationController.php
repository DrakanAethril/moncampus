<?php

namespace App\Controller;

use App\Entity\InternshipStudentEvaluation;
use App\Entity\Period;
use App\Entity\Program;
use App\Entity\User;
use App\Form\InternshipStudentEvaluationType;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramRepository;
use App\Service\GotenbergUnavailableException;
use App\Service\InternshipBookletBuilder;
use App\Service\InternshipBookletPdfExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// A student's own Livret Alternant self-evaluation - route-level guards (not a class-level
// staff gate) since students, not staff, are the ones reaching this area. Stays inside the
// normal layout/app.html.twig shell - students already navigate their program from there.
class ProgramInternshipEvaluationController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/internship/my-evaluations', name: 'app_program_internship_my_evaluations')]
    #[IsGranted('ROLE_STUDENT')]
    public function myEvaluations(int $id, ProgramRepository $repository, PeriodRepository $periodRepository, InternshipStudentEvaluationRepository $evaluationRepository, InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);

        $evaluationsByPeriodId = [];
        foreach ($evaluationRepository->findAllForStudentAndProgram($this->currentUser(), $program) as $evaluation) {
            $evaluationsByPeriodId[$evaluation->getPeriod()->getId()] = $evaluation;
        }

        $rows = array_map(
            static fn (Period $period): array => [
                'period' => $period,
                'submitted' => isset($evaluationsByPeriodId[$period->getId()]),
            ],
            $periodRepository->findAllActiveForProgram($program),
        );

        return $this->render('program/internship_my_evaluations.html.twig', [
            'program' => $program,
            'rows' => $rows,
            // The "view my booklet" button only makes sense once a tutor contract is on file -
            // same guard as myBooklet() itself, checked here just to decide whether to show it.
            'hasTutorLink' => null !== $tutorLinkRepository->findOneForStudentAndProgram($this->currentUser(), $program),
        ]);
    }

    #[Route(path: '/programs/{id}/internship/my-evaluations/{periodId}', name: 'app_program_internship_my_evaluation', requirements: ['periodId' => '\d+'])]
    #[IsGranted('ROLE_STUDENT')]
    public function myEvaluation(int $id, int $periodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, PeriodRepository $periodRepository, InternshipStudentEvaluationRepository $evaluationRepository): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();
        $student = $this->currentUser();

        $evaluation = $evaluationRepository->findOneForStudentAndPeriod($student, $period);
        $isEdit = null !== $evaluation;

        if (!$isEdit) {
            $evaluation = new InternshipStudentEvaluation($student, $program, $period);
        }

        $form = $this->createForm(InternshipStudentEvaluationType::class, $evaluation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setValidationDate(new \DateTimeImmutable());
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'internshipStudentEvaluationSavedFlashMessage');

            return $this->redirectToRoute('app_program_internship_my_evaluations', ['id' => $program->getId()]);
        }

        return $this->render('program/internship_my_evaluation.html.twig', [
            'form' => $form,
            'program' => $program,
            'period' => $period,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/my-evaluations/booklet', name: 'app_program_internship_my_booklet')]
    #[IsGranted('ROLE_STUDENT')]
    public function myBooklet(int $id, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletBuilder $bookletBuilder): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        // No tutor contract on file yet means no booklet to show, rather than a partially-built
        // page - the same "nothing to reach yet" shape as any other missing-resource 404 here.
        $tutorLink = $tutorLinkRepository->findOneForStudentAndProgram($this->currentUser(), $program) ?? throw $this->createNotFoundException();

        return $this->render('internship/booklet.html.twig', $bookletBuilder->build($tutorLink));
    }

    #[Route(path: '/programs/{id}/internship/my-evaluations/booklet/pdf', name: 'app_program_internship_my_booklet_pdf')]
    #[IsGranted('ROLE_STUDENT')]
    public function myBookletPdf(int $id, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletPdfExporter $exporter): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $tutorLink = $tutorLinkRepository->findOneForStudentAndProgram($this->currentUser(), $program) ?? throw $this->createNotFoundException();

        try {
            $pdf = $exporter->export($tutorLink, $this->renderView(...));
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'internshipBookletPdfExportFailedFlashMessage');

            // Redirects to the evaluations list (not the booklet "View" route) on failure -
            // internship/booklet.html.twig extends base.html.twig directly with no flash-message
            // region, so an error flash set there would never actually be shown to the user.
            return $this->redirectToRoute('app_program_internship_my_evaluations', ['id' => $program->getId()]);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('livret-alternant-%s.pdf', $tutorLink->getStudent()->getUsername())),
        ]);
    }

    private function findProgramForStudentOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($this->currentUser())) {
            throw $this->createNotFoundException();
        }

        $this->assertProgramFeatureEnabled($program->isInternshipManagementEnabled());

        return $program;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }
}

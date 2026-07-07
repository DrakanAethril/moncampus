<?php

namespace App\Controller;

use App\Entity\InternshipStudentEvaluation;
use App\Entity\Period;
use App\Entity\Program;
use App\Entity\User;
use App\Form\InternshipStudentEvaluationType;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// A student's own Livret Alternant self-evaluation - route-level guards (not a class-level
// staff gate) since students, not staff, are the ones reaching this area. Stays inside the
// normal layout/app.html.twig shell - students already navigate their program from there.
class ProgramInternshipEvaluationController extends AbstractController
{
    #[Route(path: '/programs/{id}/internship/my-evaluations', name: 'app_program_internship_my_evaluations')]
    #[IsGranted('ROLE_STUDENT')]
    public function myEvaluations(int $id, ProgramRepository $repository, PeriodRepository $periodRepository, InternshipStudentEvaluationRepository $evaluationRepository): Response
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
            $periodRepository->findAllActive(),
        );

        return $this->render('program/internship_my_evaluations.html.twig', [
            'program' => $program,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/programs/{id}/internship/my-evaluations/{periodId}', name: 'app_program_internship_my_evaluation')]
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

    private function findProgramForStudentOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($this->currentUser())) {
            throw $this->createNotFoundException();
        }

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

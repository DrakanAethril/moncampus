<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTeamEvaluation;
use App\Entity\InternshipTutorLink;
use App\Enum\Feature;
use App\Form\InternshipTeamEvaluationType;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'évaluation par l'équipe pédagogique, période par période, pour un InternshipTutorLink.
 *
 * Split out of the former ProgramInternshipController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::UfaBooklet)]
class InternshipTeamEvaluationController extends AbstractController
{
    use ProgramInternshipTrait;

    #[Route(path: '/ufa/programs/{id}/tutors/{tutorLinkId}/team-evaluations', name: 'app_ufa_formation_tutors_team_evaluations')]
    public function tutorLinkTeamEvaluations(int $id, int $tutorLinkId, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTeamEvaluationRepository $teamEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);

        $evaluationsByPeriodId = [];
        foreach ($teamEvaluationRepository->findAllForStudentAndProgram($tutorLink->getStudent(), $program) as $evaluation) {
            $evaluationsByPeriodId[$evaluation->getEvaluationPeriod()->getId()] = $evaluation;
        }

        $rows = array_map(
            static fn (InternshipEvaluationPeriod $evaluationPeriod): array => [
                'period' => $evaluationPeriod,
                'submitted' => isset($evaluationsByPeriodId[$evaluationPeriod->getId()]),
            ],
            $evaluationPeriodRepository->findAllActiveForProgram($program),
        );

        return $this->render('program/internship_tutor_team_evaluations.html.twig', [
            'program' => $program,
            'tutorLink' => $tutorLink,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/ufa/programs/{id}/tutors/{tutorLinkId}/team-evaluations/{periodId}', name: 'app_ufa_formation_tutors_team_evaluation', requirements: ['periodId' => '\d+'])]
    public function tutorLinkTeamEvaluation(int $id, int $tutorLinkId, int $periodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTeamEvaluationRepository $teamEvaluationRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $tutorLink = $this->findTutorLinkOrNotFound($tutorLinkRepository, $program, $tutorLinkId);
        $evaluationPeriod = $evaluationPeriodRepository->find($periodId) ?? throw $this->createNotFoundException();

        $evaluation = $teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($tutorLink->getStudent(), $evaluationPeriod);
        $isEdit = null !== $evaluation;

        if (!$isEdit) {
            $evaluation = new InternshipTeamEvaluation($tutorLink->getStudent(), $program, $evaluationPeriod);
        }

        $form = $this->createForm(InternshipTeamEvaluationType::class, $evaluation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var InternshipTeamEvaluation $entity */
            $entity = $form->getData();
            $entity->setValidationDate(new \DateTimeImmutable());
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'internshipTeamEvaluationSavedFlashMessage');

            return $this->redirectToRoute('app_ufa_formation_tutors_team_evaluations', ['id' => $program->getId(), 'tutorLinkId' => $tutorLink->getId()]);
        }

        return $this->render('program/internship_tutor_team_evaluation.html.twig', [
            'form' => $form,
            'program' => $program,
            'tutorLink' => $tutorLink,
            'period' => $evaluationPeriod,
        ]);
    }
}

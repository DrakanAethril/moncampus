<?php

namespace App\Controller;

use App\Entity\InternshipSkillGroup;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorEvaluationBehavior;
use App\Entity\InternshipTutorEvaluationSkill;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\Period;
use App\Entity\User;
use App\Form\InternshipTutorEvaluationType;
use App\Repository\InternshipBehaviorCriteriaRepository;
use App\Repository\InternshipSkillGroupRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Security\Voter\InternshipTutorLinkVoter;
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

// The entreprise tutor's own area (ROLE_EXTERNAL) - deliberately outside the staff/student
// layout/app.html.twig shell (see templates/layout/external.html.twig), since a tutor has no
// use for the Section/Program/Paramètres navigation built for staff and students.
#[IsGranted('ROLE_EXTERNAL')]
class InternshipTutorEvaluationController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/my/internship', name: 'app_internship_tutor_home')]
    public function home(EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipTutorEvaluationRepository $evaluationRepository, PeriodRepository $periodRepository): Response
    {
        $user = $this->currentUser();
        // Only surface links whose Program still has the internship feature turned on - a
        // tutor with links across multiple programs keeps seeing the ones still enabled instead
        // of losing the whole home page over one disabled Program.
        $tutorLinks = array_values(array_filter(
            $tutorLinkRepository->findActiveForTutorUser($user),
            static fn (InternshipTutorLink $tutorLink): bool => $tutorLink->getProgram()->isInternshipManagementEnabled(),
        ));

        // Opportunistic first-login linking: a link matched only by tutorEmail (the LDAP
        // "external" account didn't exist yet when staff created the link) gets attached to
        // this now-authenticated User once and for all.
        $linked = false;
        foreach ($tutorLinks as $tutorLink) {
            if (null === $tutorLink->getTutor()) {
                $tutorLink->setTutor($user);
                $linked = true;
            }
        }
        if ($linked) {
            $entityManager->flush();
        }

        $periods = $periodRepository->findAllActive();

        $rows = array_map(
            function (InternshipTutorLink $tutorLink) use ($periods, $evaluationRepository): array {
                $evaluationsByPeriodId = [];
                foreach ($evaluationRepository->findAllForTutorLink($tutorLink) as $evaluation) {
                    $evaluationsByPeriodId[$evaluation->getPeriod()->getId()] = $evaluation;
                }

                return [
                    'tutorLink' => $tutorLink,
                    'periods' => array_map(
                        static fn (Period $period): array => [
                            'period' => $period,
                            'submitted' => isset($evaluationsByPeriodId[$period->getId()]),
                        ],
                        $periods,
                    ),
                ];
            },
            $tutorLinks,
        );

        return $this->render('internship_tutor/home.html.twig', [
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/my/internship/{tutorLinkId}/{periodId}', name: 'app_internship_tutor_evaluate', requirements: ['tutorLinkId' => '\d+', 'periodId' => '\d+'])]
    public function evaluate(int $tutorLinkId, int $periodId, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, PeriodRepository $periodRepository, InternshipTutorEvaluationRepository $evaluationRepository, InternshipBehaviorCriteriaRepository $behaviorCriteriaRepository, InternshipSkillGroupRepository $skillGroupRepository, ProgramStudentOptionRepository $studentOptionRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(InternshipTutorLinkVoter::EVALUATE, $tutorLink);
        $this->assertProgramFeatureEnabled($tutorLink->getProgram()->isInternshipManagementEnabled());

        $evaluation = $evaluationRepository->findOneForTutorLinkAndPeriod($tutorLink, $period);
        $isEdit = null !== $evaluation;

        if (!$isEdit) {
            $evaluation = new InternshipTutorEvaluation($tutorLink, $period);
        }

        // Idempotently attach one row per active criteria - only for criteria that don't
        // already have a row, so re-visiting after staff add a new criteria shows the new row
        // without wiping previously-answered ones.
        $existingBehaviorCriteriaIds = array_map(
            static fn (InternshipTutorEvaluationBehavior $row): ?int => $row->getBehaviorCriteria()?->getId(),
            $evaluation->getBehaviorEvaluations()->toArray(),
        );
        foreach ($behaviorCriteriaRepository->findAllActive() as $criteria) {
            if (!\in_array($criteria->getId(), $existingBehaviorCriteriaIds, true)) {
                $evaluation->addBehaviorEvaluation(new InternshipTutorEvaluationBehavior($criteria));
            }
        }

        $existingSkillCriterionIds = array_map(
            static fn (InternshipTutorEvaluationSkill $row): ?int => $row->getSkillCriterion()?->getId(),
            $evaluation->getSkillEvaluations()->toArray(),
        );
        $studentOptionIds = array_map(
            static fn (Option $option): int => $option->getId(),
            $studentOptionRepository->findOptionsForStudent($tutorLink->getProgram(), $tutorLink->getStudent()),
        );
        $skillGroups = array_values(array_filter(
            $skillGroupRepository->findAllActiveForProgram($tutorLink->getProgram()),
            static fn (InternshipSkillGroup $group): bool => $group->isVisibleForStudentOptions($studentOptionIds),
        ));
        foreach ($skillGroups as $skillGroup) {
            foreach ($skillGroup->getCriteria() as $skillCriterion) {
                if (null === $skillCriterion->getInactiveDate() && !\in_array($skillCriterion->getId(), $existingSkillCriterionIds, true)) {
                    $evaluation->addSkillEvaluation(new InternshipTutorEvaluationSkill($skillCriterion));
                }
            }
        }

        $form = $this->createForm(InternshipTutorEvaluationType::class, $evaluation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setValidationDate(new \DateTimeImmutable());
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'internshipTutorEvaluationSavedFlashMessage');

            return $this->redirectToRoute('app_internship_tutor_home');
        }

        return $this->render('internship_tutor/evaluate.html.twig', [
            'form' => $form,
            'tutorLink' => $tutorLink,
            'period' => $period,
            'skillGroups' => $skillGroups,
        ]);
    }

    #[Route(path: '/my/internship/{tutorLinkId}/booklet', name: 'app_internship_tutor_booklet')]
    public function booklet(int $tutorLinkId, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletBuilder $bookletBuilder): Response
    {
        $tutorLink = $tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();
        // Viewing the booklet is a strict subset of what evaluating already grants - same Voter
        // check as evaluate(), no new attribute needed.
        $this->denyAccessUnlessGranted(InternshipTutorLinkVoter::EVALUATE, $tutorLink);
        $this->assertProgramFeatureEnabled($tutorLink->getProgram()->isInternshipManagementEnabled());

        return $this->render('internship/booklet.html.twig', $bookletBuilder->build($tutorLink));
    }

    #[Route(path: '/my/internship/{tutorLinkId}/booklet/pdf', name: 'app_internship_tutor_booklet_pdf')]
    public function bookletPdf(int $tutorLinkId, InternshipTutorLinkRepository $tutorLinkRepository, InternshipBookletPdfExporter $exporter): Response
    {
        $tutorLink = $tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(InternshipTutorLinkVoter::EVALUATE, $tutorLink);
        $this->assertProgramFeatureEnabled($tutorLink->getProgram()->isInternshipManagementEnabled());

        try {
            $pdf = $exporter->export($tutorLink, $this->renderView(...));
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'internshipBookletPdfExportFailedFlashMessage');

            // Redirects to the home list (not the booklet "View" route) on failure -
            // internship/booklet.html.twig extends base.html.twig directly with no flash-message
            // region, so an error flash set there would never actually be shown to the user.
            return $this->redirectToRoute('app_internship_tutor_home');
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('livret-alternant-%s.pdf', $tutorLink->getStudent()->getUsername())),
        ]);
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

<?php

namespace App\Controller;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Form\InternshipTutorEvaluationType;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\SkillLevelRepository;
use App\Security\Voter\InternshipTutorLinkVoter;
use App\Service\GotenbergUnavailableException;
use App\Service\InternshipBookletBuilder;
use App\Service\InternshipBookletPdfExporter;
use App\Service\InternshipTutorEvaluationBuilder;
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
    public function home(EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipTutorEvaluationRepository $evaluationRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository): Response
    {
        $user = $this->currentUser();
        // Only surface links whose Program still has the internship feature turned on - a
        // tutor with links across multiple programs keeps seeing the ones still enabled instead
        // of losing the whole home page over one disabled Program.
        $tutorLinks = array_values(array_filter(
            $tutorLinkRepository->findActiveForTutorUser($user),
            static fn (InternshipTutorLink $tutorLink): bool => $tutorLink->getProgram()->isInternshipManagementEnabled(),
        ));

        // Opportunistic first-login linking: a link matched only by tutorEmail or by the login
        // generated for its spawned LdapManageUser request (the LDAP "external" account didn't
        // exist yet when staff created the link - see InternshipTutorLinkRepository::
        // findActiveForTutorUser()) gets attached to this now-authenticated User once and for all.
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

        // Each tutor link's Program has its own evaluation periods, so the candidates (unlike the
        // rest of this method) can't be resolved once for every link - resolved per-link inside
        // the closure below.
        $rows = array_map(
            function (InternshipTutorLink $tutorLink) use ($evaluationPeriodRepository, $evaluationRepository): array {
                $evaluationsByPeriodId = [];
                foreach ($evaluationRepository->findAllForTutorLink($tutorLink) as $evaluation) {
                    $evaluationsByPeriodId[$evaluation->getEvaluationPeriod()->getId()] = $evaluation;
                }

                return [
                    'tutorLink' => $tutorLink,
                    'periods' => array_map(
                        static fn (InternshipEvaluationPeriod $evaluationPeriod): array => [
                            'period' => $evaluationPeriod,
                            'submitted' => isset($evaluationsByPeriodId[$evaluationPeriod->getId()]),
                        ],
                        $evaluationPeriodRepository->findAllActiveForProgram($tutorLink->getProgram()),
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
    public function evaluate(int $tutorLinkId, int $periodId, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTutorEvaluationBuilder $evaluationBuilder, SkillLevelRepository $skillLevelRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($tutorLinkId) ?? throw $this->createNotFoundException();
        $evaluationPeriod = $evaluationPeriodRepository->find($periodId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(InternshipTutorLinkVoter::EVALUATE, $tutorLink);
        $this->assertProgramFeatureEnabled($tutorLink->getProgram()->isInternshipManagementEnabled());

        ['evaluation' => $evaluation, 'isEdit' => $isEdit, 'skillGroups' => $skillGroups] = $evaluationBuilder->findOrPrepare($tutorLink, $evaluationPeriod);

        $skillLevels = $skillLevelRepository->findAllActiveForProgramOrGlobal($tutorLink->getProgram());
        $form = $this->createForm(InternshipTutorEvaluationType::class, $evaluation, ['skillLevelChoices' => $skillLevels]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setValidationDate(new \DateTimeImmutable());
            $entity->setLastEditedBy($this->currentUser());
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'internshipTutorEvaluationSavedFlashMessage');

            return $this->redirectToRoute('app_internship_tutor_home');
        }

        return $this->render('internship_tutor/evaluate.html.twig', [
            'form' => $form,
            'tutorLink' => $tutorLink,
            'period' => $evaluationPeriod,
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

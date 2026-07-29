<?php

namespace App\Controller;

use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Form\InternshipAlternanceType;
use App\Repository\EnterpriseRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Service\AlternanceEngagementService;
use App\Service\AlternancePeriodStatusResolver;
use App\Service\AlternanceStepStatus;
use App\Service\InternshipTutorProvisioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The new UFA "Alternances" surface (design/design_handoff_ufa_alternance, tours 32-34): the
 * dashboard replacing the old Formations-list at /ufa (see UfaController's own docblock - that
 * controller keeps the Formations tabs/Configuration/placeholder routes, this one owns everything
 * under /ufa/alternances plus the repointed /ufa itself), and "Créer une alternance". Later phases
 * add the engagement/period-wizard/suivi/relance/livret routes to this same controller.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class UfaAlternanceController extends AbstractController
{
    #[Route(path: '/ufa', name: 'app_ufa')]
    public function dashboard(Request $request, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository, InternshipTutorLinkRepository $tutorLinkRepository, EnterpriseRepository $enterpriseRepository, AlternancePeriodStatusResolver $statusResolver, TranslatorInterface $translator): Response
    {
        $currentSchoolYear = $schoolYearRepository->findCurrentOrMostRecent();
        $schoolYears = $schoolYearRepository->findAllActiveOrderedByMostRecent();

        $selectedYearId = $request->query->getInt('year', 0);
        $selectedYear = 0 !== $selectedYearId ? $this->findSchoolYearOrNotFound($schoolYears, $selectedYearId) : $currentSchoolYear;
        $isPastYear = null !== $currentSchoolYear && null !== $selectedYear && $selectedYear->getId() !== $currentSchoolYear->getId();

        $formations = null !== $selectedYear ? $programRepository->findAlternanceForSchoolYear($selectedYear, true) : [];

        $selectedFormationId = $request->query->getInt('formation', 0);
        $selectedFormation = 0 !== $selectedFormationId
            ? $this->findFormationOrNotFound($formations, $selectedFormationId)
            : ($formations[0] ?? null);

        $selectedEnterpriseId = $request->query->getInt('enterprise', 0);
        $selectedEnterprise = 0 !== $selectedEnterpriseId ? $enterpriseRepository->find($selectedEnterpriseId) : null;

        $showAll = $request->query->getBoolean('all');
        $search = trim((string) $request->query->get('search', ''));

        $rows = [];
        if (null !== $selectedFormation) {
            foreach ($tutorLinkRepository->findForDashboard($selectedFormation, $showAll, $selectedEnterprise, '' !== $search ? $search : null) as $tutorLink) {
                $status = $statusResolver->resolveCurrentStep($tutorLink);
                $rows[] = [
                    'tutorLink' => $tutorLink,
                    'status' => $status,
                    'badge' => $isPastYear && null === $tutorLink->getInactiveDate() && AlternanceStepStatus::STEP_INACTIVE !== $status->step
                        ? ['label' => $translator->trans('ufaAlternanceStatusYearClosedBadgeLabel'), 'class' => 'bg-green-lt']
                        : $statusResolver->badgeFor($status),
                    'isPastYear' => $isPastYear,
                ];
            }
        }

        return $this->render('ufa/alternance/dashboard.html.twig', [
            'schoolYears' => $schoolYears,
            'selectedYear' => $selectedYear,
            'isPastYear' => $isPastYear,
            'formations' => $formations,
            'selectedFormation' => $selectedFormation,
            'enterprises' => $enterpriseRepository->findAllActiveOrderedByName(),
            'selectedEnterprise' => $selectedEnterprise,
            'showAll' => $showAll,
            'search' => $search,
            'rows' => $rows,
            'kpiTotal' => null !== $selectedYear ? $tutorLinkRepository->countActiveForSchoolYear($selectedYear) : 0,
            'kpiFormations' => \count($formations),
            'kpiApprentissage' => null !== $selectedYear ? $tutorLinkRepository->countActiveForSchoolYearAndContractType($selectedYear, ContractTypeCode::Apprentissage) : 0,
            'kpiProfessionnalisation' => null !== $selectedYear ? $tutorLinkRepository->countActiveForSchoolYearAndContractType($selectedYear, ContractTypeCode::Professionnalisation) : 0,
        ]);
    }

    #[Route(path: '/ufa/alternances/new', name: 'app_ufa_alternance_new')]
    public function createAlternance(Request $request, EntityManagerInterface $entityManager, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository, InternshipTutorProvisioningService $provisioningService, AlternanceEngagementService $engagementService): Response
    {
        $schoolYear = $schoolYearRepository->findCurrentOrMostRecent() ?? throw $this->createNotFoundException();
        $alternancePrograms = $programRepository->findAlternanceForSchoolYear($schoolYear);

        $student = null;
        $program = null;
        if ($request->isMethod('POST')) {
            [$student, $program] = $this->resolveAlternanceStudent($alternancePrograms, $request->request->get('student'));
        }

        // A Program is required to construct InternshipTutorLink - fall back to the first
        // alternance Program of the year until a student is actually picked, purely so the form
        // object can exist before submission; $program is re-resolved from the picked student on
        // every POST above, so this fallback never survives an actual submit.
        $tutorLink = new InternshipTutorLink($program ?? $alternancePrograms[0] ?? throw $this->createNotFoundException());
        if (null !== $student) {
            $tutorLink->setStudent($student);
        }

        $form = $this->createForm(InternshipAlternanceType::class, $tutorLink);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $student) {
            if (null === $tutorLink->getTutor()) {
                $provisioningService->provision($tutorLink, $this->currentUser());
            }

            $tutorLink->setSupervisor($program?->getReferentTeachers()->first() ?: null);
            $tutorLink->setCreatedBy($this->currentUser());

            $entityManager->persist($tutorLink);
            $entityManager->flush();

            $engagementService->findOrCreate($tutorLink);
            $engagementService->sendEngagementInvites($tutorLink);

            $this->addFlash('success', 'ufaAlternanceCreatedFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
        }

        return $this->render('ufa/alternance/new.html.twig', [
            'form' => $form,
            'student' => $student,
            'program' => $program,
        ]);
    }

    // Placeholder for the real "Suivi de l'alternance" page (34a/34b, built in a later phase) -
    // exists now purely so createAlternance() below has somewhere valid to redirect to; every
    // other screen (engagement, period wizards, livret) will link here once built.
    #[Route(path: '/ufa/alternances/{id}', name: 'app_ufa_alternance_show', requirements: ['id' => '\d+'])]
    public function show(int $id, InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();

        return $this->render('ufa/placeholder.html.twig', [
            'pageTitleKey' => 'ufaAlternanceShowPageHeading',
            'tutorLink' => $tutorLink,
        ]);
    }

    // Placeholder for the real livret reader (26d, built in a later phase) - exists now purely so
    // the dashboard's "Livret" row action has somewhere valid to link to.
    #[Route(path: '/ufa/alternances/{id}/livret', name: 'app_ufa_alternance_livret', requirements: ['id' => '\d+'])]
    public function livret(int $id, InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();

        return $this->render('ufa/placeholder.html.twig', [
            'pageTitleKey' => 'ufaAlternanceLivretPageHeading',
            'tutorLink' => $tutorLink,
        ]);
    }

    // Backs the "Alternant" tom-select ajax field (32a) - only students already enrolled in one
    // of the current school year's alternance Programs are eligible, matching the spec's "jamais
    // créé depuis l'UFA".
    #[Route(path: '/ufa/alternances/eleve-search', name: 'app_ufa_alternance_student_search')]
    public function studentSearch(Request $request, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository): JsonResponse
    {
        $schoolYear = $schoolYearRepository->findCurrentOrMostRecent();
        $limit = 20;
        $query = mb_strtolower((string) $request->query->get('q', ''));

        $candidates = [];
        foreach (null !== $schoolYear ? $programRepository->findAlternanceForSchoolYear($schoolYear) : [] as $program) {
            foreach ($program->getStudents() as $student) {
                if ('' === $query || str_contains(mb_strtolower($student->getDisplayName() ?? $student->getUsername()), $query)) {
                    // The formation name is folded into the option text (rather than a separate
                    // field) so section 1's read-only "Formation (UFA)" display can be filled
                    // client-side straight from the picked tom-select option, with no second
                    // round-trip - see new.html.twig.
                    $candidates[$student->getId()] = [$student, $program];
                }
            }
        }
        $candidates = array_values($candidates);

        return $this->json([
            'results' => array_map(static fn (array $pair): array => [
                'id' => $pair[0]->getId(),
                'text' => \sprintf('%s — %s', $pair[0]->getDisplayName() ?? $pair[0]->getUsername(), $pair[1]->getDisplayShortName()),
                'formation' => $pair[1]->getDisplayShortName(),
            ], \array_slice($candidates, 0, $limit)),
            'pagination' => ['more' => \count($candidates) > $limit],
        ]);
    }

    // Backs the "Rechercher un tuteur" tom-select ajax field (32a/32b) - see
    // InternshipTutorLinkRepository::searchDistinctTutors(), each result's "id" is an
    // InternshipTutorLink id (the closest thing to a Tutor id this app has, see the feature's
    // plan doc, architecture call 0.1), resolved back into tutor fields by
    // InternshipAlternanceType's SUBMIT listener.
    #[Route(path: '/ufa/alternances/tuteur-search', name: 'app_ufa_alternance_tutor_search')]
    public function tutorSearch(Request $request, InternshipTutorLinkRepository $tutorLinkRepository): JsonResponse
    {
        $limit = 20;
        $results = $tutorLinkRepository->searchDistinctTutors((string) $request->query->get('q', ''), $limit + 1);

        return $this->json([
            'results' => array_map(static fn (InternshipTutorLink $link): array => [
                'id' => $link->getId(),
                'text' => \sprintf('%s %s — %s', $link->getTutorFirstName(), $link->getTutorLastName(), $link->getEnterprise()?->getName() ?? $link->getTutorEmail()),
            ], \array_slice($results, 0, $limit)),
            'pagination' => ['more' => \count($results) > $limit],
        ]);
    }

    // Plain <form method="post"> submit from the dashboard row (no JS confirm dialog - this app
    // has no generic standalone confirm+fetch controller outside DataTables-driven lists, and
    // this table is plain server-rendered, not a DataTable) - CSRF travels as the body field.
    #[Route(path: '/ufa/alternances/{id}/deactivate', name: 'app_ufa_alternance_deactivate', methods: ['POST'])]
    public function deactivate(int $id, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('ufa_alternance_deactivate', $request);

        $tutorLink->setInactiveDate(new \DateTimeImmutable());
        $tutorLink->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        $this->addFlash('success', 'ufaAlternanceDeactivatedFlashMessage');

        return $this->redirectToRoute('app_ufa', $request->query->all());
    }

    #[Route(path: '/ufa/alternances/{id}/reactivate', name: 'app_ufa_alternance_reactivate', methods: ['POST'])]
    public function reactivate(int $id, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('ufa_alternance_deactivate', $request);

        $tutorLink->setInactiveDate(null);
        $tutorLink->setInactivatedBy(null);
        $entityManager->flush();

        $this->addFlash('success', 'ufaAlternanceReactivatedFlashMessage');

        return $this->redirectToRoute('app_ufa', $request->query->all());
    }

    /** @param list<Program> $alternancePrograms */
    private function resolveAlternanceStudent(array $alternancePrograms, mixed $studentId): array
    {
        if (!is_numeric($studentId)) {
            return [null, null];
        }

        foreach ($alternancePrograms as $program) {
            foreach ($program->getStudents() as $student) {
                if ($student->getId() === (int) $studentId) {
                    return [$student, $program];
                }
            }
        }

        return [null, null];
    }

    /** @param list<Program> $formations */
    private function findFormationOrNotFound(array $formations, int $id): Program
    {
        foreach ($formations as $formation) {
            if ($formation->getId() === $id) {
                return $formation;
            }
        }

        throw $this->createNotFoundException();
    }

    /** @param list<SchoolYear> $schoolYears */
    private function findSchoolYearOrNotFound(array $schoolYears, int $id): SchoolYear
    {
        foreach ($schoolYears as $schoolYear) {
            if ($schoolYear->getId() === $id) {
                return $schoolYear;
            }
        }

        throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    // For plain <form method="post"> submissions - the token travels as a body field (name="_token").
    private function assertValidFormToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}

<?php

namespace App\Controller;

use App\Entity\ContractType;
use App\Entity\InternshipOptionExamModality;
use App\Entity\InternshipOptionLegalName;
use App\Entity\InternshipProgramInfo;
use App\Entity\Program;
use App\Entity\ProgramContractModality;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Form\InternshipExamModalityType;
use App\Form\InternshipLegalNameType;
use App\Form\UfaFormationType;
use App\Repository\ContractTypeRepository;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipOptionExamModalityRepository;
use App\Repository\InternshipOptionLegalNameRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ModalityRepository;
use App\Repository\ProgramContractModalityRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

// The UFA top-level nav's own controller: the "Tableau de bord" liste (19a, the bare /ufa route),
// "Nouvelle UFA" (19b), and the "Contrats"/"Tuteurs" placeholders (not yet designed - see
// design_handoff_ufa/README.md). The 4 Formation tabs (24a-24d) are also here, reusing the exact
// same repositories/forms/content partials as ProgramInternshipController's own "Paramétrage >
// Livret Alternant" pages - a deliberate second, thinner set of routes/shell (only 4 tabs, UFA
// breadcrumb, no Tuteurs tab) rather than touching that older, still fully working nav path.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class UfaController extends AbstractController
{
    #[Route(path: '/ufa', name: 'app_ufa')]
    public function dashboard(Request $request, ProgramRepository $programRepository, SchoolYearRepository $schoolYearRepository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository): Response
    {
        $schoolYears = $schoolYearRepository->findAllActiveOrderedByMostRecent();
        $selectedYearId = $request->query->getInt('year', 0);
        $selectedYear = 0 !== $selectedYearId
            ? $this->findSchoolYearOrNotFound($schoolYears, $selectedYearId)
            : $schoolYearRepository->findCurrentOrMostRecent();

        $formations = null !== $selectedYear ? $programRepository->findAlternanceForSchoolYear($selectedYear) : [];

        return $this->render('ufa/dashboard.html.twig', [
            'schoolYears' => $schoolYears,
            'selectedYear' => $selectedYear,
            'counters' => $this->computeCounters($formations, $tutorLinkRepository, $evaluationPeriodRepository, $tutorEvaluationRepository),
        ]);
    }

    // Backs the 19a DataTable - re-filters by the same "year" query param as dashboard() above,
    // client-side via the generic data-datatable-filter-param mechanism (datatable_controller.js).
    // No pagination/search server-side beyond that: an establishment's count of alternance
    // Formations is small enough that DataTables' own client-side search over one already-small
    // JSON payload is plenty, unlike the big rosters the "serverSide: true" DataTables elsewhere
    // in this app are built for - draw/recordsTotal/recordsFiltered are still echoed back so the
    // shared datatable_controller.js doesn't need a "non-serverSide" branch of its own.
    #[Route(path: '/ufa/data', name: 'app_ufa_data')]
    public function dashboardData(Request $request, ProgramRepository $programRepository, SchoolYearRepository $schoolYearRepository, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, TranslatorInterface $translator): JsonResponse
    {
        $draw = $request->query->getInt('draw', 1);
        $schoolYears = $schoolYearRepository->findAllActiveOrderedByMostRecent();
        $selectedYearId = $request->query->getInt('year', 0);
        $selectedYear = 0 !== $selectedYearId
            ? $this->findSchoolYearOrNotFound($schoolYears, $selectedYearId)
            : $schoolYearRepository->findCurrentOrMostRecent();

        $includeInactive = $request->query->getBoolean('includeInactive');
        $formations = null !== $selectedYear ? $programRepository->findAlternanceForSchoolYear($selectedYear, $includeInactive) : [];
        $rows = array_map(fn (Program $program): array => $this->formationRow($program, $tutorLinkRepository, $evaluationPeriodRepository, $tutorEvaluationRepository), $formations);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => \count($rows),
            'recordsFiltered' => \count($rows),
            'data' => array_map(fn (array $row): array => [
                'id' => $row['program']->getId(),
                'isInactive' => null !== $row['program']->getInactiveDate(),
                'name' => sprintf(
                    '<a href="%s">%s</a>',
                    htmlspecialchars($this->generateUrl('app_ufa_formation_evaluation_periods', ['id' => $row['program']->getId()])),
                    htmlspecialchars($row['program']->getDisplayShortName()),
                ),
                'formationName' => $row['program']->getName(),
                'responsableName' => $row['responsableName'],
                'studentCount' => $row['studentCount'] > 0 ? $row['studentCount'] : '—',
                'evaluationStatusLabel' => match ($row['evaluationStatus']) {
                    'none' => $translator->trans('ufaEvaluationStatusNoneLabel'),
                    'pending' => $translator->trans('ufaEvaluationStatusPendingLabel', ['%count%' => $row['pendingCount']]),
                    default => $translator->trans('ufaEvaluationStatusOkLabel'),
                },
                'evaluationStatusClass' => match ($row['evaluationStatus']) {
                    'none' => 'bg-secondary-lt',
                    'pending' => 'bg-orange-lt',
                    default => 'bg-green-lt',
                },
            ], $rows),
        ]);
    }

    // NOTE: the "Reprendre les tuteurs de l'an dernier" checkbox in ufa/dashboard_new.html.twig is
    // currently UI-only/no-op - InternshipTutorLink is keyed to a specific student
    // (Assert\NotNull), and a freshly created Program has no students yet (each school year's
    // intake is a different set of individuals, even for the same recurring Cohort slot - see
    // Program's docblock), so there is nothing a copy action could attach the prior Program's
    // tutor links to at creation time. Flagged for product follow-up rather than silently
    // implemented against a guess.
    #[Route(path: '/ufa/nouvelle', name: 'app_ufa_new')]
    public function newFormation(Request $request, EntityManagerInterface $entityManager, UserRepository $userRepository, ModalityRepository $modalityRepository): Response
    {
        if ($request->isMethod('POST')) {
            $responsable = $this->resolveActiveTeacher($userRepository, $request->request->get('responsable'));
        }

        $form = $this->createForm(UfaFormationType::class, null);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $entity->setCreatedBy($this->currentUser());

            $modality = $modalityRepository->findOneAlternance();
            if (null !== $modality) {
                $entity->addModality($modality);
            }

            if (isset($responsable) && null !== $responsable) {
                $entity->addTeacher($responsable);
                $entity->addReferentTeacher($responsable);
            }

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', 'ufaCreatedFlashMessage');

            return $this->redirectToRoute('app_ufa_formation_evaluation_periods', ['id' => $entity->getId()]);
        }

        return $this->render('ufa/dashboard_new.html.twig', [
            'form' => $form,
        ]);
    }

    // Backs the "Responsable" ajax tom-select field - any active teacher is a candidate, same
    // convention as LaptopController::lendCandidatesSearch()/ProgramInternshipController's
    // student search.
    #[Route(path: '/ufa/nouvelle/responsable-search', name: 'app_ufa_new_responsable_search')]
    public function responsableSearch(Request $request, UserRepository $userRepository): JsonResponse
    {
        $limit = 20;
        $candidates = $userRepository->findActiveMatchingAnyRole(['ROLE_TEACHER'], [], $request->query->get('q'));

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], \array_slice($candidates, 0, $limit)),
            'pagination' => ['more' => \count($candidates) > $limit],
        ]);
    }

    #[Route(path: '/ufa/formations/{id}', name: 'app_ufa_formation_evaluation_periods')]
    public function formationEvaluationPeriods(int $id, ProgramRepository $repository): Response
    {
        return $this->renderFormationTab($id, $repository, 'evaluation_periods');
    }

    #[Route(path: '/ufa/formations/{id}/denomination', name: 'app_ufa_formation_denomination')]
    public function formationDenomination(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, InternshipOptionLegalNameRepository $legalNameRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);
        $isNew = null === $info;

        if ($isNew) {
            $info = new InternshipProgramInfo($program);
        }

        $form = $this->createForm(InternshipLegalNameType::class, $info);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->stampAuditFields($info, !$isNew);
            $entityManager->persist($info);
            $this->syncOptionLegalNames($program, $request, $entityManager, $legalNameRepository);
            $entityManager->flush();

            $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

            return $this->redirectToRoute('app_ufa_formation_denomination', ['id' => $program->getId()]);
        }

        return $this->render('ufa/formation.html.twig', [
            'program' => $program,
            'activeTab' => 'denomination',
            'form' => $form,
            'info' => $info,
            'legalNamesByOptionId' => $legalNameRepository->findMapForProgram($program),
        ]);
    }

    // Same "presence of a row is the override" sync as ProgramInternshipController's own
    // syncOptionLegalNames() - duplicated rather than shared, matching this codebase's existing
    // convention of small per-controller private helpers (see e.g. userLabel()/stampAuditFields()
    // repeated verbatim across LaptopController/ProgramInternshipController/etc.).
    private function syncOptionLegalNames(Program $program, Request $request, EntityManagerInterface $entityManager, InternshipOptionLegalNameRepository $legalNameRepository): void
    {
        $submittedNames = $request->request->all('legalNames');

        foreach ($program->getOptions() as $option) {
            $raw = trim((string) ($submittedNames[$option->getId()] ?? ''));
            $existingOverride = $legalNameRepository->findOneForProgramAndOption($program, $option);

            if ('' === $raw) {
                if (null !== $existingOverride) {
                    $entityManager->remove($existingOverride);
                }

                continue;
            }

            if (null !== $existingOverride) {
                $existingOverride->setLegalName($raw);
            } else {
                $entityManager->persist(new InternshipOptionLegalName($program, $option, $raw));
            }
        }
    }

    #[Route(path: '/ufa/formations/{id}/contract-modalities', name: 'app_ufa_formation_contract_modalities')]
    public function formationContractModalities(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ContractTypeRepository $contractTypeRepository, ProgramContractModalityRepository $modalityRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        if ($request->isMethod('POST')) {
            $this->assertValidToken('program_internship_contract_modalities', $request);
            $this->syncContractModalities($program, $request, $entityManager, $contractTypeRepository, $modalityRepository, $sanitizer);
            $entityManager->flush();

            $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

            return $this->redirectToRoute('app_ufa_formation_contract_modalities', ['id' => $program->getId()]);
        }

        return $this->render('ufa/formation.html.twig', [
            'program' => $program,
            'activeTab' => 'contract_modalities',
            'resetRoute' => 'app_ufa_formation_contract_modalities_reset',
            'blocks' => array_map(
                function (ContractTypeCode $code) use ($program, $contractTypeRepository, $modalityRepository): array {
                    $contractType = $contractTypeRepository->findOneByCode($code) ?? new ContractType($code);

                    return [
                        'contractType' => $contractType,
                        'override' => null !== $contractType->getId() ? $modalityRepository->findOneForProgramAndContractType($program, $contractType) : null,
                    ];
                },
                ContractTypeCode::cases(),
            ),
        ]);
    }

    #[Route(path: '/ufa/formations/{id}/contract-modalities/{code}/reset', name: 'app_ufa_formation_contract_modalities_reset', methods: ['POST'])]
    public function resetContractModalityOverride(int $id, string $code, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, ContractTypeRepository $contractTypeRepository, ProgramContractModalityRepository $modalityRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $contractType = $contractTypeRepository->findOneByCode(ContractTypeCode::from($code)) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_internship_contract_modalities', $request);

        $override = $modalityRepository->findOneForProgramAndContractType($program, $contractType);
        if (null !== $override) {
            $entityManager->remove($override);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_ufa_formation_contract_modalities', ['id' => $program->getId()]);
    }

    // Same "presence of non-blank submitted text is the override" sync as
    // ProgramInternshipController::syncContractModalities() - both routes ultimately point at the
    // same underlying ContractType/ProgramContractModality rows, so a Formation reached from
    // either nav path stays in sync; only the controller/template differ.
    private function syncContractModalities(Program $program, Request $request, EntityManagerInterface $entityManager, ContractTypeRepository $contractTypeRepository, ProgramContractModalityRepository $modalityRepository, HtmlSanitizerInterface $sanitizer): void
    {
        $submitted = $request->request->all('modalities');

        foreach (ContractTypeCode::cases() as $code) {
            $contractType = $contractTypeRepository->findOneByCode($code);
            if (null === $contractType) {
                $contractType = (new ContractType($code))->setCreatedBy($this->currentUser());
                $entityManager->persist($contractType);
            }

            $raw = trim($sanitizer->sanitize((string) ($submitted[$code->value] ?? '')));
            $existingOverride = $modalityRepository->findOneForProgramAndContractType($program, $contractType);

            if ('' === $raw) {
                if (null !== $existingOverride) {
                    $entityManager->remove($existingOverride);
                }

                continue;
            }

            if (null !== $existingOverride) {
                $existingOverride->setModalitiesHtml($raw);
            } else {
                $entityManager->persist((new ProgramContractModality($program, $contractType, $raw))->setCreatedBy($this->currentUser()));
            }
        }
    }

    #[Route(path: '/ufa/formations/{id}/exam-modalities', name: 'app_ufa_formation_exam_modalities')]
    public function formationExamModalities(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipProgramInfoRepository $infoRepository, InternshipOptionExamModalityRepository $examModalityRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $info = $infoRepository->findOneByProgram($program);
        $isNew = null === $info;

        if ($isNew) {
            $info = new InternshipProgramInfo($program);
        }

        $form = $this->createForm(InternshipExamModalityType::class, $info);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $info->setExamModalityText($this->sanitizeOrNull($sanitizer, $info->getExamModalityText()));
            $this->stampAuditFields($info, !$isNew);

            $entityManager->persist($info);
            $this->syncOptionExamModalities($program, $request, $entityManager, $examModalityRepository, $sanitizer);
            $entityManager->flush();

            $this->addFlash('success', 'internshipProgramInfoUpdatedFlashMessage');

            return $this->redirectToRoute('app_ufa_formation_exam_modalities', ['id' => $program->getId()]);
        }

        return $this->render('ufa/formation.html.twig', [
            'program' => $program,
            'activeTab' => 'exam_modalities',
            'form' => $form,
            'info' => $info,
            'examModalitiesByOptionId' => $examModalityRepository->findMapForProgram($program),
        ]);
    }

    private function syncOptionExamModalities(Program $program, Request $request, EntityManagerInterface $entityManager, InternshipOptionExamModalityRepository $examModalityRepository, HtmlSanitizerInterface $sanitizer): void
    {
        $submittedTexts = $request->request->all('examModalities');

        foreach ($program->getOptions() as $option) {
            $raw = trim($sanitizer->sanitize((string) ($submittedTexts[$option->getId()] ?? '')));
            $existingOverride = $examModalityRepository->findOneForProgramAndOption($program, $option);

            if ('' === $raw) {
                if (null !== $existingOverride) {
                    $entityManager->remove($existingOverride);
                }

                continue;
            }

            if (null !== $existingOverride) {
                $existingOverride->setExamModalityText($raw);
            } else {
                $entityManager->persist(new InternshipOptionExamModality($program, $option, $raw));
            }
        }
    }

    #[Route(path: '/ufa/contrats', name: 'app_ufa_contracts')]
    public function contracts(): Response
    {
        return $this->render('ufa/placeholder.html.twig', ['pageTitleKey' => 'ufaContractsNavLabel']);
    }

    #[Route(path: '/ufa/tuteurs', name: 'app_ufa_tutors')]
    public function tutors(): Response
    {
        return $this->render('ufa/placeholder.html.twig', ['pageTitleKey' => 'ufaTutorsNavLabel']);
    }

    private function renderFormationTab(int $id, ProgramRepository $repository, string $tab): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('ufa/formation.html.twig', [
            'program' => $program,
            'activeTab' => $tab,
        ]);
    }

    /** @return array{contractsInProgress: int, contractsToComplete: int, evaluationsPending: int, currentPeriodName: ?string} */
    private function computeCounters(array $formations, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository): array
    {
        $today = new \DateTimeImmutable();
        $contractsInProgress = 0;
        $contractsToComplete = 0;
        $evaluationsPending = 0;
        $currentPeriodName = null;

        foreach ($formations as $program) {
            $periods = $evaluationPeriodRepository->findAllActiveForProgram($program);
            foreach ($periods as $period) {
                if (null === $currentPeriodName && $period->getStartDate() <= $today && $period->getEndDate() >= $today) {
                    $currentPeriodName = $period->getName();
                }
            }

            foreach ($tutorLinkRepository->findAllActiveForProgram($program) as $tutorLink) {
                $hasContractDates = null !== $tutorLink->getContractStartDate() && null !== $tutorLink->getContractEndDate();

                if (null === $tutorLink->getEnterprise() || !$hasContractDates) {
                    ++$contractsToComplete;
                } elseif ($tutorLink->getContractStartDate() <= $today && $tutorLink->getContractEndDate() >= $today) {
                    ++$contractsInProgress;
                }

                $submittedPeriodIds = array_map(
                    static fn ($evaluation) => $evaluation->getEvaluationPeriod()->getId(),
                    $tutorEvaluationRepository->findAllForTutorLink($tutorLink),
                );
                $evaluationsPending += \count(array_filter($periods, static fn ($period) => !\in_array($period->getId(), $submittedPeriodIds, true)));
            }
        }

        return [
            'contractsInProgress' => $contractsInProgress,
            'contractsToComplete' => $contractsToComplete,
            'evaluationsPending' => $evaluationsPending,
            'currentPeriodName' => $currentPeriodName,
        ];
    }

    /** @return array{program: Program, studentCount: int, responsableName: string, evaluationStatus: string, pendingCount: int} */
    private function formationRow(Program $program, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository): array
    {
        $tutorLinks = $tutorLinkRepository->findAllActiveForProgram($program);
        $periods = $evaluationPeriodRepository->findAllActiveForProgram($program);

        $pendingCount = 0;
        foreach ($tutorLinks as $tutorLink) {
            $submittedPeriodIds = array_map(
                static fn ($evaluation) => $evaluation->getEvaluationPeriod()->getId(),
                $tutorEvaluationRepository->findAllForTutorLink($tutorLink),
            );
            $pendingCount += \count(array_filter($periods, static fn ($period) => !\in_array($period->getId(), $submittedPeriodIds, true)));
        }

        $referentTeacher = $program->getReferentTeachers()->first() ?: null;

        return [
            'program' => $program,
            'studentCount' => $program->getStudents()->count(),
            'responsableName' => $referentTeacher ? ($referentTeacher->getDisplayName() ?? $referentTeacher->getUsername()) : '—',
            'evaluationStatus' => match (true) {
                [] === $tutorLinks => 'none',
                $pendingCount > 0 => 'pending',
                default => 'ok',
            },
            'pendingCount' => $pendingCount,
        ];
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

    private function resolveActiveTeacher(UserRepository $userRepository, mixed $userId): ?User
    {
        if (!is_numeric($userId)) {
            return null;
        }

        $user = $userRepository->find((int) $userId);

        return null !== $user && null === $user->getInactiveDate() ? $user : null;
    }

    private function findOrNotFound(int $id, ProgramRepository $repository): Program
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
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

    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function sanitizeOrNull(HtmlSanitizerInterface $sanitizer, ?string $html): ?string
    {
        return null !== $html && '' !== $html ? $sanitizer->sanitize($html) : $html;
    }
}

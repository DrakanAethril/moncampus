<?php

namespace App\Controller;

use App\Entity\ContractType;
use App\Entity\InternshipOptionExamModality;
use App\Entity\InternshipOptionLegalName;
use App\Entity\InternshipProgramInfo;
use App\Entity\Program;
use App\Entity\ProgramContractModality;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Form\InternshipExamModalityType;
use App\Form\InternshipLegalNameType;
use App\Form\UfaFormationType;
use App\Repository\ContractTypeRepository;
use App\Repository\InternshipOptionExamModalityRepository;
use App\Repository\InternshipOptionLegalNameRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\ModalityRepository;
use App\Repository\ProgramContractModalityRepository;
use App\Repository\ProgramRepository;
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

// The UFA top-level nav's own controller: "Nouvelle UFA" (19b), and the "Contrats" placeholder
// (not yet designed - see design_handoff_ufa/README.md). The 4 Formation tabs (24a-24d) are also
// here, reusing the exact same repositories/forms/content partials as ProgramInternshipController's
// own "Paramétrage > Livret Alternant" pages - a deliberate second, thinner set of routes/shell
// (only 4 tabs, UFA breadcrumb, no Tuteurs tab) rather than touching that older, still fully
// working nav path. The "Tableau de bord" (bare /ufa route) and "Tuteurs" routes moved to
// UfaAlternanceController - see its own docblock; this controller no longer owns them.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class UfaController extends AbstractController
{
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
            'saveRoute' => 'app_ufa_formation_contract_modalities',
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
            'resetExamModalityRoute' => 'app_ufa_formation_exam_modalities_reset',
        ]);
    }

    #[Route(path: '/ufa/formations/{id}/exam-modalities/{optionId}/reset', name: 'app_ufa_formation_exam_modalities_reset', methods: ['POST'])]
    public function resetOptionExamModality(int $id, int $optionId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipOptionExamModalityRepository $examModalityRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        // Submitted as "reset_token", not "_token" - see ProgramInternshipController::
        // resetOptionExamModality()'s equivalent comment.
        if (!$this->isCsrfTokenValid('program_internship_exam_modalities', $request->request->get('reset_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        foreach ($program->getOptions() as $option) {
            if ($option->getId() === $optionId) {
                $override = $examModalityRepository->findOneForProgramAndOption($program, $option);
                if (null !== $override) {
                    $entityManager->remove($override);
                    $entityManager->flush();
                }
                break;
            }
        }

        return $this->redirectToRoute('app_ufa_formation_exam_modalities', ['id' => $program->getId()]);
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

    // For plain <form method="post"> submissions (contract/exam modalities save/reset) - the
    // token travels as a body field (name="_token"), not a header. This controller has no
    // fetch/AJAX action that would need the header-based check instead.
    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function sanitizeOrNull(HtmlSanitizerInterface $sanitizer, ?string $html): ?string
    {
        return null !== $html && '' !== $html ? $sanitizer->sanitize($html) : $html;
    }
}

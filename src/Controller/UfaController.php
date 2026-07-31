<?php

namespace App\Controller;

use App\Entity\ContractType;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipOptionExamModality;
use App\Entity\InternshipOptionLegalName;
use App\Entity\InternshipProgramInfo;
use App\Entity\Program;
use App\Entity\ProgramContractModality;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Form\InternshipEvaluationPeriodType;
use App\Form\InternshipExamModalityType;
use App\Form\InternshipLegalNameType;
use App\Repository\ContractTypeRepository;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipOptionExamModalityRepository;
use App\Repository\InternshipOptionLegalNameRepository;
use App\Repository\InternshipProgramInfoRepository;
use App\Repository\ProgramContractModalityRepository;
use App\Repository\ProgramRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The UFA top-level nav's own controller: the "Contrats" placeholder (not yet designed - see
// design_handoff_ufa/README.md) and the 4 Formation tabs (24a-24d), which reuse the exact same
// repositories/forms as ProgramInternshipController's own
// "Paramétrage > Livret Alternant" pages but with their own turn-24 templates
// (templates/ufa/formation/ - plain periods table, collapsible modality blocks) - a deliberate
// second, thinner set of routes/shell (only 4 tabs, UFA breadcrumb, no Tuteurs tab) rather than
// touching that older, still fully working nav path. The "Tableau de bord" (bare /ufa route) and "Tuteurs" routes moved to
// UfaAlternanceController - see its own docblock; this controller no longer owns them.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class UfaController extends AbstractController
{
    // 24a - a plain table of the formation's active periods ("les petites listes ... sont des
    // tableaux simples sans barre DataTables" - design_handoff_ufa rule 4), unlike the old
    // Program > Paramétrage path's DataTable. Create/edit render as a cm-panel overlay on top
    // of this same list (rule 6), same shape as UfaConfigurationController's behavior routes.
    #[Route(path: '/ufa/formations/{id}', name: 'app_ufa_formation_evaluation_periods')]
    public function formationEvaluationPeriods(int $id, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository): Response
    {
        return $this->renderEvaluationPeriods($this->findOrNotFound($id, $repository), $evaluationPeriodRepository);
    }

    #[Route(path: '/ufa/formations/{id}/evaluation-periods/new', name: 'app_ufa_formation_evaluation_periods_new')]
    #[Route(path: '/ufa/formations/{id}/evaluation-periods/{evaluationPeriodId}/edit', name: 'app_ufa_formation_evaluation_periods_edit')]
    public function formationEvaluationPeriodForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, ?int $evaluationPeriodId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $isEdit = null !== $evaluationPeriodId;
        $evaluationPeriod = $isEdit ? $this->findEvaluationPeriodOrNotFound($evaluationPeriodRepository, $program, $evaluationPeriodId) : null;

        $form = $this->createForm(InternshipEvaluationPeriodType::class, $evaluationPeriod, ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipEvaluationPeriodUpdatedFlashMessage' : 'internshipEvaluationPeriodCreatedFlashMessage');

            return $this->redirectToRoute('app_ufa_formation_evaluation_periods', ['id' => $program->getId()]);
        }

        return $this->renderEvaluationPeriods($program, $evaluationPeriodRepository, panelForm: $form, panelIsEdit: $isEdit);
    }

    #[Route(path: '/ufa/formations/{id}/evaluation-periods/{evaluationPeriodId}/deactivate', name: 'app_ufa_formation_evaluation_periods_deactivate', methods: ['POST'])]
    public function deactivateFormationEvaluationPeriod(int $id, int $evaluationPeriodId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, InternshipEvaluationPeriodRepository $evaluationPeriodRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $evaluationPeriod = $this->findEvaluationPeriodOrNotFound($evaluationPeriodRepository, $program, $evaluationPeriodId);
        $this->assertValidToken('ufa_deactivate', $request);

        $evaluationPeriod->setInactiveDate(new \DateTimeImmutable());
        $evaluationPeriod->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->redirectToRoute('app_ufa_formation_evaluation_periods', ['id' => $program->getId()]);
    }

    private function renderEvaluationPeriods(Program $program, InternshipEvaluationPeriodRepository $evaluationPeriodRepository, ?FormInterface $panelForm = null, bool $panelIsEdit = false): Response
    {
        return $this->render('ufa/formation.html.twig', [
            'program' => $program,
            'activeTab' => 'evaluation_periods',
            'evaluationPeriods' => $evaluationPeriodRepository->findAllActiveForProgram($program),
            'panelForm' => $panelForm,
            'panelIsEdit' => $panelIsEdit,
        ]);
    }

    private function findEvaluationPeriodOrNotFound(InternshipEvaluationPeriodRepository $repository, Program $program, int $evaluationPeriodId): InternshipEvaluationPeriod
    {
        $evaluationPeriod = $repository->find($evaluationPeriodId);
        if (null === $evaluationPeriod || $evaluationPeriod->getProgram() !== $program) {
            throw $this->createNotFoundException();
        }

        return $evaluationPeriod;
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

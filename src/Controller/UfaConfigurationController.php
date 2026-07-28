<?php

namespace App\Controller;

use App\Entity\ContractType;
use App\Entity\InternshipBehaviorCriteria;
use App\Entity\InternshipBehaviorLevel;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Form\InternshipBehaviorCriteriaType;
use App\Form\InternshipFormationCenterType;
use App\Repository\ContractTypeRepository;
use App\Repository\InternshipBehaviorCriteriaRepository;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\ProgramContractModalityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// "UFA > Configuration" - Centre de formation (21a), Modalités de contrats (22a), Liste des
// comportements (23a/23b). Replaces the old "Paramètres > UFA" nav entry/UfaSettingsController:
// formation_center and behavior are the same features simply moved here (routes renamed from
// app_settings_ufa_* to app_ufa_configuration_*); loan_conditions moved instead to "Ordinateurs
// portables > Configuration" (see LaptopController) since it's about laptop loans, not UFA
// configuration proper. Modalités de contrats (contract_modalities) is genuinely new - the
// center-level default per ContractType, with the list of Formations currently overriding it.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class UfaConfigurationController extends AbstractController
{
    #[Route(path: '/ufa/configuration', name: 'app_ufa_configuration')]
    #[Route(path: '/ufa/configuration/centre-de-formation', name: 'app_ufa_configuration_formation_center')]
    public function formationCenterTab(Request $request, EntityManagerInterface $entityManager, InternshipFormationCenterRepository $repository): Response
    {
        $formationCenter = $repository->getOrCreate();

        if (null === $formationCenter->getCreatedBy()) {
            $formationCenter->setCreatedBy($this->currentUser());
        }

        $form = $this->createForm(InternshipFormationCenterType::class, $formationCenter);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formationCenter->setLastUpdatedBy($this->currentUser());
            $formationCenter->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'internshipFormationCenterUpdatedFlashMessage');

            return $this->redirectToRoute('app_ufa_configuration_formation_center');
        }

        return $this->render('ufa/configuration.html.twig', [
            'activeTab' => 'formation_center',
            'form' => $form,
        ]);
    }

    #[Route(path: '/ufa/configuration/modalites-de-contrats', name: 'app_ufa_configuration_contract_modalities')]
    public function contractModalitiesTab(Request $request, EntityManagerInterface $entityManager, ContractTypeRepository $contractTypeRepository, ProgramContractModalityRepository $modalityRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer): Response
    {
        $selectedCode = ContractTypeCode::tryFrom((string) $request->query->get('type', '')) ?? ContractTypeCode::Apprentissage;
        $selectedContractType = $contractTypeRepository->findOneByCode($selectedCode);

        if ($request->isMethod('POST')) {
            $this->assertValidToken('ufa_configuration_contract_modalities', $request);

            if (null === $selectedContractType) {
                $selectedContractType = (new ContractType($selectedCode))->setCreatedBy($this->currentUser());
                $entityManager->persist($selectedContractType);
            }

            $raw = trim($sanitizer->sanitize((string) $request->request->get('defaultModalitiesHtml', '')));
            $selectedContractType->setDefaultModalitiesHtml('' !== $raw ? $raw : null);
            $selectedContractType->setLastUpdatedBy($this->currentUser());
            $selectedContractType->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'ufaContractModalitiesUpdatedFlashMessage');

            return $this->redirectToRoute('app_ufa_configuration_contract_modalities', ['type' => $selectedCode->value]);
        }

        $contractTypes = array_map(
            fn (ContractTypeCode $code): array => [
                'code' => $code,
                'contractType' => $contractTypeRepository->findOneByCode($code),
                'overrideCount' => null !== ($ct = $contractTypeRepository->findOneByCode($code)) ? $modalityRepository->countForContractType($ct) : 0,
            ],
            ContractTypeCode::cases(),
        );

        return $this->render('ufa/configuration.html.twig', [
            'activeTab' => 'contract_modalities',
            'contractTypes' => $contractTypes,
            'selectedCode' => $selectedCode,
            'selectedContractType' => $selectedContractType,
            'overrides' => null !== $selectedContractType ? $modalityRepository->findAllForContractType($selectedContractType) : [],
        ]);
    }

    #[Route(path: '/ufa/configuration/modalites-de-contrats/{code}/reset/{programId}', name: 'app_ufa_configuration_contract_modalities_reset', methods: ['POST'])]
    public function resetContractModalityOverride(string $code, int $programId, Request $request, EntityManagerInterface $entityManager, ContractTypeRepository $contractTypeRepository, ProgramContractModalityRepository $modalityRepository): Response
    {
        $this->assertValidToken('ufa_configuration_contract_modalities', $request);
        $contractType = $contractTypeRepository->findOneByCode(ContractTypeCode::from($code)) ?? throw $this->createNotFoundException();

        foreach ($modalityRepository->findAllForContractType($contractType) as $override) {
            if ($override->getProgram()?->getId() === $programId) {
                $entityManager->remove($override);
                $entityManager->flush();
                break;
            }
        }

        return $this->redirectToRoute('app_ufa_configuration_contract_modalities', ['type' => $code]);
    }

    #[Route(path: '/ufa/configuration/comportements', name: 'app_ufa_configuration_behavior')]
    public function behaviorTab(): Response
    {
        return $this->render('ufa/configuration.html.twig', ['activeTab' => 'behavior']);
    }

    #[Route(path: '/ufa/configuration/comportements/new', name: 'app_ufa_configuration_behavior_new')]
    #[Route(path: '/ufa/configuration/comportements/{id}/edit', name: 'app_ufa_configuration_behavior_edit')]
    public function behaviorCriteriaForm(Request $request, EntityManagerInterface $entityManager, InternshipBehaviorCriteriaRepository $repository, ?int $id = null): Response
    {
        $isEdit = null !== $id;
        $criteria = $isEdit ? $this->findOrNotFound($repository, $id) : new InternshipBehaviorCriteria();

        if (!$isEdit) {
            for ($levelNumber = 1; $levelNumber <= 5; ++$levelNumber) {
                $criteria->addLevel(new InternshipBehaviorLevel($levelNumber));
            }
        }

        $form = $this->createForm(InternshipBehaviorCriteriaType::class, $criteria);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'internshipBehaviorUpdatedFlashMessage' : 'internshipBehaviorCreatedFlashMessage');

            return $this->redirectToRoute('app_ufa_configuration_behavior');
        }

        return $this->render('ufa/behavior_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/ufa/configuration/comportements/{id}/deactivate', name: 'app_ufa_configuration_behavior_deactivate', methods: ['POST'])]
    public function deactivateBehaviorCriteria(Request $request, EntityManagerInterface $entityManager, InternshipBehaviorCriteriaRepository $repository, int $id): JsonResponse
    {
        $criteria = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $criteria->setInactiveDate(new \DateTimeImmutable());
        $criteria->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/ufa/configuration/comportements/data', name: 'app_ufa_configuration_behavior_data')]
    public function behaviorCriteriaData(Request $request, InternshipBehaviorCriteriaRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (InternshipBehaviorCriteria $criteria): array => [
                    'id' => $criteria->getId(),
                    'isInactive' => null !== $criteria->getInactiveDate(),
                    'label' => $criteria->getLabel(),
                    'orderIndex' => $criteria->getOrderIndex(),
                    'creationDate' => $criteria->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $criteria->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($criteria->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($criteria->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($criteria->getLastUpdatedBy()),
                    'lastUpdatedDate' => $criteria->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));
        $includeInactive = $request->query->getBoolean('includeInactive');

        return [$draw, $start, $length, $search, $includeInactive];
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function userLabel(?User $user): string
    {
        if (null === $user) {
            return '—';
        }

        return $user->getDisplayName() ?? $user->getUsername();
    }

    /**
     * @template T of object
     *
     * @param ObjectRepository<T> $repository
     *
     * @return T
     */
    private function findOrNotFound(ObjectRepository $repository, int $id): object
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
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

    private function assertValidDeactivateToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('ufa_deactivate', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}

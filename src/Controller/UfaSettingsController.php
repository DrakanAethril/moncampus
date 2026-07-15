<?php

namespace App\Controller;

use App\Entity\InternshipBehaviorCriteria;
use App\Entity\InternshipBehaviorLevel;
use App\Entity\LaptopConditionType;
use App\Entity\User;
use App\Form\InternshipBehaviorCriteriaType;
use App\Form\InternshipFormationCenterType;
use App\Form\LaptopConditionTypeType;
use App\Repository\InternshipBehaviorCriteriaRepository;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\LaptopConditionTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// "Paramètres > UFA" nav entry (Settings > UFA) - formerly two separate areas: this used to be
// just the loan_conditions tab (its own sibling of Configuration/Pédagogique), and
// formation_center/behavior used to live under their own standalone "Livret Alternant" nav entry
// (the old SettingsInternshipController, now merged in here and deleted). Tab order is
// formation_center, behavior, loan_conditions - "États" (loan conditions) is deliberately third,
// after the pre-existing Livret Alternant tabs it was merged alongside.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class UfaSettingsController extends AbstractController
{
    #[Route(path: '/settings/ufa/configuration', name: 'app_settings_ufa_configuration')]
    #[Route(path: '/settings/ufa/formation-center', name: 'app_settings_ufa_formation_center')]
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

            return $this->redirectToRoute('app_settings_ufa_formation_center');
        }

        return $this->render('settings/ufa_configuration.html.twig', [
            'activeTab' => 'formation_center',
            'form' => $form,
        ]);
    }

    #[Route(path: '/settings/ufa/behavior', name: 'app_settings_ufa_behavior')]
    public function behaviorTab(): Response
    {
        return $this->renderTab('behavior');
    }

    #[Route(path: '/settings/ufa/behavior/new', name: 'app_settings_ufa_behavior_new')]
    #[Route(path: '/settings/ufa/behavior/{id}/edit', name: 'app_settings_ufa_behavior_edit')]
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

            return $this->redirectToRoute('app_settings_ufa_behavior');
        }

        return $this->render('settings/ufa_behavior_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/ufa/behavior/{id}/deactivate', name: 'app_settings_ufa_behavior_deactivate', methods: ['POST'])]
    public function deactivateBehaviorCriteria(Request $request, EntityManagerInterface $entityManager, InternshipBehaviorCriteriaRepository $repository, int $id): JsonResponse
    {
        $criteria = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $criteria->setInactiveDate(new \DateTimeImmutable());
        $criteria->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/ufa/behavior/data', name: 'app_settings_ufa_behavior_data')]
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

    #[Route(path: '/settings/ufa/loan-conditions', name: 'app_settings_ufa_loan_conditions')]
    public function loanConditionsTab(): Response
    {
        return $this->renderTab('loan_conditions');
    }

    #[Route(path: '/settings/ufa/loan-conditions/new', name: 'app_settings_ufa_loan_conditions_new')]
    #[Route(path: '/settings/ufa/loan-conditions/{id}/edit', name: 'app_settings_ufa_loan_conditions_edit')]
    public function loanConditionTypeForm(Request $request, EntityManagerInterface $entityManager, LaptopConditionTypeRepository $repository, ?int $id = null): Response
    {
        $conditionType = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $conditionType;

        $form = $this->createForm(LaptopConditionTypeType::class, $conditionType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'loanConditionTypeUpdatedFlashMessage' : 'loanConditionTypeCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_ufa_loan_conditions');
        }

        return $this->render('settings/loan_condition_type_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/ufa/loan-conditions/{id}/deactivate', name: 'app_settings_ufa_loan_conditions_deactivate', methods: ['POST'])]
    public function deactivateLoanConditionType(Request $request, EntityManagerInterface $entityManager, LaptopConditionTypeRepository $repository, int $id): JsonResponse
    {
        $conditionType = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $conditionType->setInactiveDate(new \DateTimeImmutable());
        $conditionType->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/ufa/loan-conditions/data', name: 'app_settings_ufa_loan_conditions_data')]
    public function loanConditionsData(Request $request, LaptopConditionTypeRepository $repository): JsonResponse
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
                fn (LaptopConditionType $conditionType): array => [
                    'id' => $conditionType->getId(),
                    'isInactive' => null !== $conditionType->getInactiveDate(),
                    'name' => $conditionType->getName(),
                    'color' => $conditionType->getColor(),
                    'creationDate' => $conditionType->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $conditionType->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($conditionType->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($conditionType->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($conditionType->getLastUpdatedBy()),
                    'lastUpdatedDate' => $conditionType->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    private function renderTab(string $tab): Response
    {
        return $this->render('settings/ufa_configuration.html.twig', ['activeTab' => $tab]);
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
}

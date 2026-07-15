<?php

namespace App\Controller;

use App\Entity\LaptopConditionType;
use App\Entity\User;
use App\Form\LaptopConditionTypeType;
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

// New "Paramètres > UFA" nav entry (Settings > UFA), a sibling of Configuration/Pédagogique but
// unrelated to Structure - own controller/shell rather than folding into
// SettingsStructureController::TAB_GROUPS, same "one route per tab" shape (each tab a real
// navigation to its own route, not every tab's DataTable loading up front). Just one tab today
// (loan_conditions - "Prêts - États"), built the same tabbed-shell way as Configuration/
// Pédagogique so adding a second UFA tab later is a small diff, not a restructure.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class UfaSettingsController extends AbstractController
{
    #[Route(path: '/settings/ufa/configuration', name: 'app_settings_ufa_configuration')]
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

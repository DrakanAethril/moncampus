<?php

namespace App\Controller;

use App\Entity\Laptop;
use App\Entity\LaptopLoan;
use App\Entity\User;
use App\Form\LaptopLoanLendType;
use App\Form\LaptopLoanReturnType;
use App\Form\LaptopType;
use App\Repository\LaptopConditionTypeRepository;
use App\Repository\LaptopLoanRepository;
use App\Repository\LaptopRepository;
use App\Repository\UserRepository;
use App\Service\LaptopStatusFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class LaptopController extends AbstractController
{
    // Both tabs render the same laptop/index.html.twig shell, which includes just the requested
    // tab's content partial based on activeTab - same "one route per tab" idea as
    // SettingsStructureController, so switching tabs doesn't fire every tab's DataTables request
    // up front.
    #[Route(path: '/laptops', name: 'app_laptops')]
    public function inventoryTab(LaptopConditionTypeRepository $conditionTypeRepository): Response
    {
        return $this->render('laptop/index.html.twig', [
            'activeTab' => 'inventory',
            'conditionTypes' => $conditionTypeRepository->findAllActive(),
        ]);
    }

    #[Route(path: '/laptops/loans', name: 'app_laptops_loans')]
    public function loansTab(): Response
    {
        return $this->render('laptop/index.html.twig', ['activeTab' => 'loans']);
    }

    #[Route(path: '/laptops/new', name: 'app_laptops_new')]
    #[Route(path: '/laptops/{id}/edit', name: 'app_laptops_edit')]
    public function laptopForm(Request $request, EntityManagerInterface $entityManager, LaptopRepository $repository, ?int $id = null): Response
    {
        $laptop = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $laptop;

        $form = $this->createForm(LaptopType::class, $laptop);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'laptopUpdatedFlashMessage' : 'laptopCreatedFlashMessage');

            return $this->redirectToRoute('app_laptops');
        }

        return $this->render('laptop/laptop_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/laptops/{id}/deactivate', name: 'app_laptops_deactivate', methods: ['POST'])]
    public function deactivateLaptop(Request $request, EntityManagerInterface $entityManager, LaptopRepository $repository, LaptopLoanRepository $loanRepository, int $id): JsonResponse
    {
        $laptop = $this->findOrNotFound($repository, $id);
        $this->assertValidToken('laptop_deactivate', $request);

        // A laptop currently on loan must be returned first - retiring it here would silently
        // strand its active LaptopLoan with no way to record the return.
        if (null !== $loanRepository->findActiveLoanForLaptop($laptop)) {
            return $this->json(['success' => false], 409);
        }

        $laptop->setInactiveDate(new \DateTimeImmutable());
        $laptop->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/laptops/{id}/history', name: 'app_laptops_history')]
    public function historyTab(LaptopRepository $repository, int $id): Response
    {
        $laptop = $this->findOrNotFound($repository, $id);

        return $this->render('laptop/history.html.twig', ['laptop' => $laptop]);
    }

    #[Route(path: '/laptops/{id}/history/data', name: 'app_laptops_history_data')]
    public function historyData(Request $request, LaptopRepository $repository, LaptopLoanRepository $loanRepository, LaptopStatusFormatter $statusFormatter, int $id): JsonResponse
    {
        $laptop = $this->findOrNotFound($repository, $id);
        [$draw, $start, $length] = $this->readSimpleDataTableParams($request);

        $total = $loanRepository->countForLaptop($laptop);
        $rows = $loanRepository->findPageForLaptop($laptop, $start, $length);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => array_map(fn (LaptopLoan $loan): array => $this->loanRow($loan, $statusFormatter), $rows),
        ]);
    }

    // Single-step lend form: the borrower is picked via an ajax tom-select field in the
    // template (see lend.html.twig) instead of a separate "browse the whole active roster in a
    // DataTable, then confirm" flow - that extra picker page/step turned out to be an unwieldy
    // way to do what's really just a lookup by name.
    #[Route(path: '/laptops/{id}/lend', name: 'app_laptops_lend')]
    public function lendForm(Request $request, EntityManagerInterface $entityManager, LaptopRepository $repository, LaptopLoanRepository $loanRepository, UserRepository $userRepository, int $id): Response
    {
        $laptop = $this->assertLendable($repository, $loanRepository, $id);

        $loan = (new LaptopLoan($laptop))->setLentBy($this->currentUser());

        // The borrower must be resolved and set before handleRequest()/isValid() runs, not
        // after like AuditableTrait's createdBy - LaptopLoan::$borrower carries an
        // Assert\NotNull, so setting it only on success would make the form permanently
        // invalid (borrower is null right up to the point isValid() runs). It's read from a
        // plain top-level "borrower" field (not a mapped form child) the same way
        // AssignmentType's manual_recipients is, since the candidate pool is the whole active
        // user roster.
        if ($request->isMethod('POST')) {
            $loan->setBorrower($this->resolveActiveBorrower($userRepository, $request->request->get('borrower')));
        }

        $form = $this->createForm(LaptopLoanLendType::class, $loan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($loan);
            $entityManager->flush();

            $this->addFlash('success', 'laptopLentFlashMessage');

            return $this->redirectToRoute('app_laptops');
        }

        return $this->render('laptop/lend.html.twig', [
            'form' => $form,
            'laptop' => $laptop,
        ]);
    }

    // Backs the borrower ajax tom-select field in lend.html.twig - only active (non-disabled)
    // users are eligible, same "DB filters what it can" convention as UserRepository's other
    // active-candidate queries (see findActiveMatchingRoles()).
    #[Route(path: '/laptops/{id}/lend-candidates', name: 'app_laptops_lend_candidates_search')]
    public function lendCandidatesSearch(Request $request, LaptopRepository $repository, LaptopLoanRepository $loanRepository, UserRepository $userRepository, int $id): JsonResponse
    {
        $this->assertLendable($repository, $loanRepository, $id);
        $limit = 20;

        $candidates = $userRepository->findActiveMatchingRoles([], [], $request->query->get('q'));

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], array_slice($candidates, 0, $limit)),
            'pagination' => ['more' => count($candidates) > $limit],
        ]);
    }

    #[Route(path: '/laptops/{id}/return', name: 'app_laptops_return')]
    public function returnForm(Request $request, EntityManagerInterface $entityManager, LaptopRepository $repository, LaptopLoanRepository $loanRepository, int $id): Response
    {
        $laptop = $this->findOrNotFound($repository, $id);
        $loan = $loanRepository->findActiveLoanForLaptop($laptop) ?? throw $this->createNotFoundException();

        $form = $this->createForm(LaptopLoanReturnType::class, $loan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $loan->setReturnedBy($this->currentUser());
            $loan->setReturnedAt(new \DateTimeImmutable());

            $entityManager->flush();

            $this->addFlash('success', 'laptopReturnedFlashMessage');

            return $this->redirectToRoute('app_laptops');
        }

        return $this->render('laptop/return.html.twig', [
            'form' => $form,
            'laptop' => $laptop,
            'loan' => $loan,
            'daysOverdue' => $loan->isOverdue() ? $loan->getDueAt()->diff(new \DateTimeImmutable())->days : null,
        ]);
    }

    #[Route(path: '/laptops/data', name: 'app_laptops_data')]
    public function inventoryData(Request $request, LaptopRepository $repository, LaptopLoanRepository $loanRepository, LaptopStatusFormatter $statusFormatter): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive, $conditionTypeId] = $this->readInventoryDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive, $conditionTypeId);
        $filteredTotal = '' !== $search || null !== $conditionTypeId ? $repository->countAll($search, $includeInactive, $conditionTypeId) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive, $conditionTypeId);

        $laptopIds = array_map(static fn (Laptop $laptop): int => $laptop->getId(), $rows);
        $activeLoansByLaptopId = $loanRepository->findActiveLoansByLaptopIds($laptopIds);
        $conditionByLaptopId = $loanRepository->findMostRecentReturnConditionsByLaptopIds($laptopIds);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                function (Laptop $laptop) use ($activeLoansByLaptopId, $conditionByLaptopId, $statusFormatter): array {
                    $activeLoan = $activeLoansByLaptopId[$laptop->getId()] ?? null;
                    $condition = $conditionByLaptopId[$laptop->getId()] ?? null;

                    return [
                        'id' => $laptop->getId(),
                        'isInactive' => null !== $laptop->getInactiveDate(),
                        'isOnLoan' => null !== $activeLoan,
                        'assetTag' => $laptop->getAssetTag(),
                        'deviceLabel' => trim(sprintf('%s %s', $laptop->getBrand() ?? '', $laptop->getModel() ?? '')) ?: '—',
                        'statusLabel' => $statusFormatter->label($laptop, $activeLoan),
                        'statusClass' => $statusFormatter->cssClass($laptop, $activeLoan),
                        'conditionName' => $condition?->getName(),
                        'conditionColor' => $condition?->getColor(),
                        'borrowerName' => null !== $activeLoan ? $this->userLabel($activeLoan->getBorrower()) : '—',
                        'dueAt' => $activeLoan?->getDueAt()?->format('d/m/Y') ?? '—',
                        'creationDate' => $laptop->getCreationDate()->format('d/m/Y H:i'),
                        'inactiveDate' => $laptop->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                        'createdByName' => $this->userLabel($laptop->getCreatedBy()),
                        'inactivatedByName' => $this->userLabel($laptop->getInactivatedBy()),
                        'lastUpdatedByName' => $this->userLabel($laptop->getLastUpdatedBy()),
                        'lastUpdatedDate' => $laptop->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                    ];
                },
                $rows,
            ),
        ]);
    }

    #[Route(path: '/laptops/loans/data', name: 'app_laptops_loans_data')]
    public function loansData(Request $request, LaptopLoanRepository $loanRepository, LaptopStatusFormatter $statusFormatter): JsonResponse
    {
        [$draw, $start, $length, $search, $onlyActive] = $this->readLoansDataTableParams($request);

        $total = $loanRepository->countAll(null, $onlyActive);
        $filteredTotal = '' !== $search ? $loanRepository->countAll($search, $onlyActive) : $total;
        $rows = $loanRepository->findPage($start, $length, '' !== $search ? $search : null, $onlyActive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(fn (LaptopLoan $loan): array => $this->loanRow($loan, $statusFormatter, includeLaptop: true), $rows),
        ]);
    }

    // Same "onlyActive"/"search" filters as loansData()'s DataTable, but every matching row at
    // once (see LaptopLoanRepository::findAllMatching()) rather than one page - backs the
    // "Exporter" button in laptop/_loans_button.html.twig.
    #[Route(path: '/laptops/loans/export', name: 'app_laptops_loans_export')]
    public function exportLoans(Request $request, LaptopLoanRepository $loanRepository, LaptopStatusFormatter $statusFormatter): StreamedResponse
    {
        $search = trim((string) ($request->query->get('search', '')));
        $onlyActive = $request->query->getBoolean('onlyActive');
        $loans = $loanRepository->findAllMatching('' !== $search ? $search : null, $onlyActive);

        $response = new StreamedResponse(function () use ($loans, $statusFormatter): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['N° inventaire', 'Emprunteur', 'Prêté par', 'Prêté le', 'État au prêt', 'Retour prévu', 'Rendu le', 'État au retour', 'Statut'], ';');

            foreach ($loans as $loan) {
                fputcsv($handle, [
                    $loan->getLaptop()->getAssetTag(),
                    $this->userLabel($loan->getBorrower()),
                    $this->userLabel($loan->getLentBy()),
                    $loan->getLentAt()->format('d/m/Y H:i'),
                    $loan->getLentConditionType()?->getName() ?? '',
                    $loan->getDueAt()?->format('d/m/Y') ?? '',
                    $loan->getReturnedAt()?->format('d/m/Y H:i') ?? '',
                    $loan->getReturnConditionType()?->getName() ?? '',
                    $statusFormatter->loanLabel($loan),
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            \sprintf('prets-ordinateurs-%s.csv', (new \DateTimeImmutable())->format('Y-m-d')),
        ));

        return $response;
    }

    private function assertLendable(LaptopRepository $repository, LaptopLoanRepository $loanRepository, int $id): Laptop
    {
        $laptop = $this->findOrNotFound($repository, $id);

        if (null !== $laptop->getInactiveDate() || null !== $loanRepository->findActiveLoanForLaptop($laptop)) {
            throw $this->createNotFoundException();
        }

        return $laptop;
    }

    // Re-resolves and re-checks the submitted borrower id server-side rather than trusting it -
    // the ajax search already only returns active users, but nothing stops a forged id for an
    // inactive one from being submitted directly.
    private function resolveActiveBorrower(UserRepository $userRepository, mixed $borrowerId): ?User
    {
        if (!is_numeric($borrowerId)) {
            return null;
        }

        $borrower = $userRepository->find((int) $borrowerId);

        return null !== $borrower && null === $borrower->getInactiveDate() ? $borrower : null;
    }

    /** @return array{id: int|null, borrowerName: string, lentByName: string, lentAt: string, dueAt: string, lentStateNotes: string, lentConditionName: ?string, lentConditionColor: ?string, returnedByName: string, returnedAt: string, returnStateNotes: string, returnConditionName: ?string, returnConditionColor: ?string, statusLabel: string, statusClass: string, assetTag?: string} */
    private function loanRow(LaptopLoan $loan, LaptopStatusFormatter $statusFormatter, bool $includeLaptop = false): array
    {
        $row = [
            'id' => $loan->getId(),
            'borrowerName' => $this->userLabel($loan->getBorrower()),
            'lentByName' => $this->userLabel($loan->getLentBy()),
            'lentAt' => $loan->getLentAt()->format('d/m/Y H:i'),
            'dueAt' => $loan->getDueAt()?->format('d/m/Y') ?? '—',
            'lentStateNotes' => $loan->getLentStateNotes(),
            'lentConditionName' => $loan->getLentConditionType()?->getName(),
            'lentConditionColor' => $loan->getLentConditionType()?->getColor(),
            'returnedByName' => $this->userLabel($loan->getReturnedBy()),
            'returnedAt' => $loan->getReturnedAt()?->format('d/m/Y H:i') ?? '—',
            'returnStateNotes' => $loan->getReturnStateNotes() ?? '—',
            'returnConditionName' => $loan->getReturnConditionType()?->getName(),
            'returnConditionColor' => $loan->getReturnConditionType()?->getColor(),
            'statusLabel' => $statusFormatter->loanLabel($loan),
            'statusClass' => $statusFormatter->loanCssClass($loan),
        ];

        if ($includeLaptop) {
            $row['assetTag'] = $loan->getLaptop()->getAssetTag();
        }

        return $row;
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool, 5: ?int} */
    private function readInventoryDataTableParams(Request $request): array
    {
        [$draw, $start, $length, $search] = $this->readSimpleDataTableParams($request, withSearch: true);
        $includeInactive = $request->query->getBoolean('includeInactive');
        $conditionTypeId = $request->query->get('conditionTypeId');
        $conditionTypeId = null !== $conditionTypeId && '' !== $conditionTypeId ? (int) $conditionTypeId : null;

        return [$draw, $start, $length, $search, $includeInactive, $conditionTypeId];
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readLoansDataTableParams(Request $request): array
    {
        [$draw, $start, $length, $search] = $this->readSimpleDataTableParams($request, withSearch: true);
        $onlyActive = $request->query->getBoolean('onlyActive');

        return [$draw, $start, $length, $search, $onlyActive];
    }

    /** @return array{0: int, 1: int, 2: int, 3: string} */
    private function readSimpleDataTableParams(Request $request, bool $withSearch = false): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = $withSearch ? trim((string) ($request->query->all('search')['value'] ?? '')) : '';

        return [$draw, $start, $length, $search];
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

    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}

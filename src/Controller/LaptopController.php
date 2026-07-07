<?php

namespace App\Controller;

use App\Entity\Laptop;
use App\Entity\LaptopLoan;
use App\Entity\User;
use App\Form\LaptopLoanLendType;
use App\Form\LaptopLoanReturnType;
use App\Form\LaptopType;
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
    public function inventoryTab(): Response
    {
        return $this->render('laptop/index.html.twig', ['activeTab' => 'inventory']);
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

    // Candidate-picker page for choosing who a laptop is being lent to - lists active users in a
    // DataTable (same "browse and pick one" shape as ProgramSettingsController's addStudentsPage)
    // rather than a plain <select>, since the borrower pool is the whole active user roster.
    // Selecting a row navigates to lendConfirmForm() to actually record the loan, instead of
    // adding immediately, because lending also requires the due date and condition notes.
    #[Route(path: '/laptops/{id}/lend', name: 'app_laptops_lend')]
    public function lendPickerPage(LaptopRepository $repository, LaptopLoanRepository $loanRepository, int $id): Response
    {
        $laptop = $this->assertLendable($repository, $loanRepository, $id);

        return $this->render('laptop/lend_pick.html.twig', ['laptop' => $laptop]);
    }

    // A distinct literal segment (not "/lend/data") so this never competes positionally with
    // lendConfirmForm()'s '/laptops/{id}/lend/{userId}' route below - Twig's path() function
    // validates route requirements even when building a '__ID__' placeholder URL template for
    // the DataTables JS controller to substitute client-side (see lend_pick.html.twig), so a
    // '\d+' requirement on {userId} to disambiguate a same-shape route would break that
    // placeholder generation outright.
    #[Route(path: '/laptops/{id}/lend-candidates', name: 'app_laptops_lend_data')]
    public function lendPickerData(Request $request, LaptopRepository $repository, LaptopLoanRepository $loanRepository, UserRepository $userRepository, int $id): JsonResponse
    {
        $this->assertLendable($repository, $loanRepository, $id);
        [$draw, $start, $length, $search] = $this->readSimpleDataTableParams($request, withSearch: true);

        $candidates = $userRepository->findActiveMatchingRoles([], [], '' !== $search ? $search : null);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => count($candidates),
            'recordsFiltered' => count($candidates),
            'data' => array_map(
                fn (User $user): array => [
                    'id' => $user->getId(),
                    'fullName' => $user->getDisplayName() ?? $user->getUsername(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail() ?? '—',
                ],
                array_slice($candidates, $start, $length),
            ),
        ]);
    }

    #[Route(path: '/laptops/{id}/lend/{userId}', name: 'app_laptops_lend_confirm')]
    public function lendConfirmForm(Request $request, EntityManagerInterface $entityManager, LaptopRepository $repository, LaptopLoanRepository $loanRepository, UserRepository $userRepository, int $id, int $userId): Response
    {
        $laptop = $this->assertLendable($repository, $loanRepository, $id);
        $borrower = $userRepository->find($userId) ?? throw $this->createNotFoundException();

        // lentBy must be set before validation, not after isValid() like AuditableTrait's
        // createdBy - unlike createdBy, LaptopLoan::$lentBy carries an Assert\NotNull, so
        // setting it only on success would make the form permanently invalid (lentBy is null
        // right up to the point isValid() runs).
        $loan = (new LaptopLoan($laptop))->setBorrower($borrower)->setLentBy($this->currentUser());

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
            'borrower' => $borrower,
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
        ]);
    }

    #[Route(path: '/laptops/data', name: 'app_laptops_data')]
    public function inventoryData(Request $request, LaptopRepository $repository, LaptopLoanRepository $loanRepository, LaptopStatusFormatter $statusFormatter): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readInventoryDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        $laptopIds = array_map(static fn (Laptop $laptop): int => $laptop->getId(), $rows);
        $activeLoansByLaptopId = $loanRepository->findActiveLoansByLaptopIds($laptopIds);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                function (Laptop $laptop) use ($activeLoansByLaptopId, $statusFormatter): array {
                    $activeLoan = $activeLoansByLaptopId[$laptop->getId()] ?? null;

                    return [
                        'id' => $laptop->getId(),
                        'isInactive' => null !== $laptop->getInactiveDate(),
                        'isOnLoan' => null !== $activeLoan,
                        'assetTag' => $laptop->getAssetTag(),
                        'deviceLabel' => trim(sprintf('%s %s', $laptop->getBrand() ?? '', $laptop->getModel() ?? '')) ?: '—',
                        'statusLabel' => $statusFormatter->label($laptop, $activeLoan),
                        'statusClass' => $statusFormatter->cssClass($laptop, $activeLoan),
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

    private function assertLendable(LaptopRepository $repository, LaptopLoanRepository $loanRepository, int $id): Laptop
    {
        $laptop = $this->findOrNotFound($repository, $id);

        if (null !== $laptop->getInactiveDate() || null !== $loanRepository->findActiveLoanForLaptop($laptop)) {
            throw $this->createNotFoundException();
        }

        return $laptop;
    }

    /** @return array{id: int|null, borrowerName: string, lentByName: string, lentAt: string, dueAt: string, lentStateNotes: string, returnedByName: string, returnedAt: string, returnStateNotes: string, returnCondition: string, statusLabel: string, statusClass: string, assetTag?: string} */
    private function loanRow(LaptopLoan $loan, LaptopStatusFormatter $statusFormatter, bool $includeLaptop = false): array
    {
        $row = [
            'id' => $loan->getId(),
            'borrowerName' => $this->userLabel($loan->getBorrower()),
            'lentByName' => $this->userLabel($loan->getLentBy()),
            'lentAt' => $loan->getLentAt()->format('d/m/Y H:i'),
            'dueAt' => $loan->getDueAt()?->format('d/m/Y') ?? '—',
            'lentStateNotes' => $loan->getLentStateNotes(),
            'returnedByName' => $this->userLabel($loan->getReturnedBy()),
            'returnedAt' => $loan->getReturnedAt()?->format('d/m/Y H:i') ?? '—',
            'returnStateNotes' => $loan->getReturnStateNotes() ?? '—',
            'returnCondition' => $loan->getReturnCondition() ?? '—',
            'statusLabel' => $statusFormatter->loanLabel($loan),
            'statusClass' => $statusFormatter->loanCssClass($loan),
        ];

        if ($includeLaptop) {
            $row['assetTag'] = $loan->getLaptop()->getAssetTag();
        }

        return $row;
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readInventoryDataTableParams(Request $request): array
    {
        [$draw, $start, $length, $search] = $this->readSimpleDataTableParams($request, withSearch: true);
        $includeInactive = $request->query->getBoolean('includeInactive');

        return [$draw, $start, $length, $search, $includeInactive];
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

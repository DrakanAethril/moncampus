<?php

namespace App\Controller;

use App\Entity\Laptop;
use App\Entity\LaptopConditionType;
use App\Entity\LaptopLoan;
use App\Entity\User;
use App\Form\LaptopConditionTypeType;
use App\Form\LaptopLoanLendType;
use App\Form\LaptopLoanReturnType;
use App\Form\LaptopType;
use App\Repository\LaptopConditionTypeRepository;
use App\Repository\LaptopLoanRepository;
use App\Repository\LaptopRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Service\LaptopStatusFormatter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class LaptopController extends AbstractController
{
    // Both tabs render the same laptop/index.html.twig shell, which includes just the requested
    // tab's content partial based on activeTab - same "one route per tab" idea as
    // the App\Controller\Settings\* controllers, so switching tabs doesn't fire every tab's DataTables request
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

    // 25c - moved here from the old "Paramètres > UFA" settings tab (formerly
    // UfaSettingsController::loanConditionsTab()) now that it's part of "Ordinateurs portables"
    // rather than a general settings area. A plain server-rendered table, not a DataTable - "les
    // petites listes (...) états du matériel sont des tableaux simples sans barre DataTables"
    // (design_handoff_ufa's rule 4), same exception as evaluation periods.
    #[Route(path: '/laptops/configuration', name: 'app_laptops_configuration')]
    public function configurationTab(Request $request, LaptopConditionTypeRepository $repository): Response
    {
        $includeInactive = $request->query->getBoolean('includeInactive');
        $conditionTypes = $repository->findAllOrdered();

        if (!$includeInactive) {
            $conditionTypes = array_values(array_filter($conditionTypes, static fn (LaptopConditionType $conditionType): bool => null === $conditionType->getInactiveDate()));
        }

        return $this->render('laptop/index.html.twig', [
            'activeTab' => 'configuration',
            'conditionTypes' => $conditionTypes,
            'includeInactive' => $includeInactive,
        ]);
    }

    #[Route(path: '/laptops/configuration/new', name: 'app_laptops_configuration_new')]
    #[Route(path: '/laptops/configuration/{id}/edit', name: 'app_laptops_configuration_edit')]
    public function conditionTypeForm(Request $request, EntityManagerInterface $entityManager, LaptopConditionTypeRepository $repository, ?int $id = null): Response
    {
        $conditionType = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $conditionType;

        $form = $this->createForm(LaptopConditionTypeType::class, $conditionType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            if (!$isEdit) {
                $entity->setOrderIndex($repository->nextOrderIndex());
            }

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'loanConditionTypeUpdatedFlashMessage' : 'loanConditionTypeCreatedFlashMessage');

            return $this->redirectToRoute('app_laptops_configuration');
        }

        return $this->render('laptop/condition_type_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/laptops/configuration/{id}/deactivate', name: 'app_laptops_configuration_deactivate', methods: ['POST'])]
    public function deactivateConditionType(Request $request, EntityManagerInterface $entityManager, LaptopConditionTypeRepository $repository, int $id): Response
    {
        $conditionType = $this->findOrNotFound($repository, $id);
        $this->assertValidFormToken('laptop_configuration_deactivate', $request);

        $conditionType->setInactiveDate(new \DateTimeImmutable());
        $conditionType->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->redirectToRoute('app_laptops_configuration', ['includeInactive' => 1]);
    }

    #[Route(path: '/laptops/configuration/{id}/reactivate', name: 'app_laptops_configuration_reactivate', methods: ['POST'])]
    public function reactivateConditionType(Request $request, EntityManagerInterface $entityManager, LaptopConditionTypeRepository $repository, int $id): Response
    {
        $conditionType = $this->findOrNotFound($repository, $id);
        $this->assertValidFormToken('laptop_configuration_deactivate', $request);

        $conditionType->setInactiveDate(null);
        $conditionType->setInactivatedBy(null);
        $entityManager->flush();

        return $this->redirectToRoute('app_laptops_configuration', ['includeInactive' => 1]);
    }

    // Same "re-fetch canonical order, apply new positions, ignore anything not in it" shape as
    // SettingsGroupsController::reorderGroupTypes().
    #[Route(path: '/laptops/configuration/reorder', name: 'app_laptops_configuration_reorder', methods: ['POST'])]
    public function reorderConditionTypes(Request $request, EntityManagerInterface $entityManager, LaptopConditionTypeRepository $repository): JsonResponse
    {
        $this->assertValidToken('laptop_configuration_reorder', $request);

        $conditionTypesById = [];
        foreach ($repository->findAllOrdered() as $conditionType) {
            $conditionTypesById[$conditionType->getId()] = $conditionType;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $ids = \is_array($data['ids'] ?? null) ? array_map(intval(...), $data['ids']) : [];

        foreach ($ids as $position => $conditionTypeId) {
            $conditionTypesById[$conditionTypeId]?->setOrderIndex($position);
        }

        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // 25b - a panel over the inventory list (same pattern as UfaConfigurationController's
    // behavior criteria panel), not a separate page - both routes render the same
    // laptop/index.html.twig shell (activeTab='inventory'), the DataTables list underneath
    // loading independently via its own ajax call regardless of which route rendered the shell.
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

        return $this->render('laptop/index.html.twig', [
            'activeTab' => 'inventory',
            'panelForm' => $form,
            'panelIsEdit' => $isEdit,
            'panelLaptop' => $laptop,
        ]);
    }

    #[Route(path: '/laptops/{id}/deactivate', name: 'app_laptops_deactivate', methods: ['POST'])]
    public function deactivateLaptop(Request $request, EntityManagerInterface $entityManager, LaptopRepository $repository, LaptopLoanRepository $loanRepository, int $id): Response
    {
        $laptop = $this->findOrNotFound($repository, $id);
        // Submitted as "deactivate_token", not "_token" - this button submits the edit panel's
        // own Symfony Form (via formaction, see templates/laptop/_inventory_content.html.twig)
        // whose built-in "_token" field is checked against a Symfony-internal id, not this one.
        if (!$this->isCsrfTokenValid('laptop_deactivate', $request->request->get('deactivate_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // A laptop currently on loan must be returned first - retiring it here would silently
        // strand its active LaptopLoan with no way to record the return.
        if (null !== $loanRepository->findActiveLoanForLaptop($laptop)) {
            $this->addFlash('error', 'laptopDeactivateHasActiveLoanMessage');

            return $this->redirectToRoute('app_laptops_edit', ['id' => $id]);
        }

        $laptop->setInactiveDate(new \DateTimeImmutable());
        $laptop->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->redirectToRoute('app_laptops');
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

    // 25a/25e's primary "Prêter un ordinateur" entry point - unlike lendForm() above (reached
    // from one specific Inventaire row, laptop already fixed), this picks BOTH the student and
    // the available laptop in the same form. The laptop is resolved from a raw "laptop" POST
    // field exactly like "borrower" already is (LaptopLoan has no setLaptop() - it's
    // constructor-only by design, see the entity's docblock - so a real Laptop must be known
    // before the entity can be constructed at all). A placeholder empty Laptop stands in only
    // for the initial GET render, when nothing has been picked yet and the form has no mapped
    // field for it anyway; submitting with no laptop actually selected is caught explicitly
    // below and surfaced as a form error, the same way a missing enterprise is on
    // InternshipTutorLinkType.
    #[Route(path: '/laptops/loans/new', name: 'app_laptops_loans_new')]
    public function newLoanForm(Request $request, EntityManagerInterface $entityManager, LaptopRepository $laptopRepository, LaptopLoanRepository $loanRepository, UserRepository $userRepository, TranslatorInterface $translator): Response
    {
        $laptop = null;

        if ($request->isMethod('POST')) {
            $laptop = $this->resolveAvailableLaptop($laptopRepository, $loanRepository, $request->request->get('laptop'));
        }

        $loan = (new LaptopLoan($laptop ?? new Laptop('')))->setLentBy($this->currentUser());

        if ($request->isMethod('POST')) {
            $loan->setBorrower($this->resolveActiveBorrower($userRepository, $request->request->get('borrower')));
        }

        $form = $this->createForm(LaptopLoanLendType::class, $loan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && null === $laptop) {
            $form->addError(new FormError($translator->trans('laptopLoanLaptopRequiredMessage')));
        }

        if ($form->isSubmitted() && $form->isValid() && null !== $laptop) {
            $entityManager->persist($loan);
            $entityManager->flush();

            $this->addFlash('success', 'laptopLentFlashMessage');

            return $this->redirectToRoute('app_laptops_loans');
        }

        return $this->render('laptop/loan_new.html.twig', [
            'form' => $form,
        ]);
    }

    // Backs the "Ordinateur disponible" ajax tom-select field in loan_new.html.twig.
    #[Route(path: '/laptops/loans/available-search', name: 'app_laptops_loans_available_search')]
    public function loanAvailableLaptopsSearch(Request $request, LaptopRepository $repository): JsonResponse
    {
        $candidates = $repository->findAvailableMatching($request->query->get('q'));

        return $this->json([
            'results' => array_map(static fn (Laptop $laptop): array => [
                'id' => $laptop->getId(),
                'text' => trim(sprintf('%s — %s %s', $laptop->getAssetTag(), $laptop->getBrand() ?? '', $laptop->getModel() ?? '')),
            ], $candidates),
            'pagination' => ['more' => false],
        ]);
    }

    // Backs the "Étudiant" ajax tom-select field in loan_new.html.twig - unlike
    // lendCandidatesSearch() above (scoped to one laptop just to assert it's still lendable),
    // this has no laptop yet to scope against.
    #[Route(path: '/laptops/loans/student-search', name: 'app_laptops_loans_student_search')]
    public function loanStudentSearch(Request $request, UserRepository $userRepository, ProgramRepository $programRepository): JsonResponse
    {
        $limit = 20;
        $candidates = $userRepository->findActiveMatchingRoles([], [], $request->query->get('q'));

        return $this->json([
            'results' => array_map(static function (User $user) use ($programRepository): array {
                $program = $programRepository->findActiveForStudent($user);

                return [
                    'id' => $user->getId(),
                    'text' => null !== $program
                        ? sprintf('%s — %s', $user->getDisplayName() ?? $user->getUsername(), $program->getDisplayShortName())
                        : ($user->getDisplayName() ?? $user->getUsername()),
                ];
            }, array_slice($candidates, 0, $limit)),
            'pagination' => ['more' => count($candidates) > $limit],
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
    public function returnForm(Request $request, EntityManagerInterface $entityManager, LaptopRepository $repository, LaptopLoanRepository $loanRepository, ProgramRepository $programRepository, int $id): Response
    {
        $laptop = $this->findOrNotFound($repository, $id);
        $loan = $loanRepository->findActiveLoanForLaptop($laptop) ?? throw $this->createNotFoundException();

        $form = $this->createForm(LaptopLoanReturnType::class, $loan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $loan->setReturnedBy($this->currentUser());

            $entityManager->flush();

            $this->addFlash('success', 'laptopReturnedFlashMessage');

            return $this->redirectToRoute('app_laptops');
        }

        return $this->render('laptop/return.html.twig', [
            'form' => $form,
            'laptop' => $laptop,
            'loan' => $loan,
            'borrowerProgram' => $programRepository->findActiveForStudent($loan->getBorrower()),
            'daysOverdue' => $loan->isOverdue() ? $loan->getDueAt()->diff(new \DateTimeImmutable())->days : null,
        ]);
    }

    // 25d - 4 columns (N° inventaire/Marque et modèle/État/Disponibilité) + Modifier only;
    // Prêter/Retourner/Historique/Désactiver moved into the edit panel (see laptopForm() below)
    // rather than a per-row action menu. "État" prefers the active loan's lentConditionType (the
    // condition it was in when it went out), falling back to the most recent *returned* loan's
    // returnConditionType, falling back to Laptop::$currentConditionType for a laptop that has
    // never been on loan yet.
    #[Route(path: '/laptops/data', name: 'app_laptops_data')]
    public function inventoryData(Request $request, LaptopRepository $repository, LaptopLoanRepository $loanRepository, TranslatorInterface $translator): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readInventoryDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        $laptopIds = array_map(static fn (Laptop $laptop): int => $laptop->getId(), $rows);
        $activeLoansByLaptopId = $loanRepository->findActiveLoansByLaptopIds($laptopIds);
        $lastReturnConditionByLaptopId = $loanRepository->findMostRecentReturnConditionsByLaptopIds($laptopIds);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                function (Laptop $laptop) use ($activeLoansByLaptopId, $lastReturnConditionByLaptopId, $translator): array {
                    $activeLoan = $activeLoansByLaptopId[$laptop->getId()] ?? null;
                    $condition = $activeLoan?->getLentConditionType() ?? $lastReturnConditionByLaptopId[$laptop->getId()] ?? $laptop->getCurrentConditionType();

                    $availability = match (true) {
                        null !== $laptop->getInactiveDate() => $translator->trans('laptopOutOfFleetLabel'),
                        null !== $activeLoan => $translator->trans('laptopLentToLabel', ['%name%' => $this->userLabel($activeLoan->getBorrower())]),
                        default => $translator->trans('laptopStatusAvailableLabel'),
                    };

                    return [
                        'id' => $laptop->getId(),
                        'isInactive' => null !== $laptop->getInactiveDate(),
                        // Le numéro d'inventaire identifie la ligne : il est en gras sur la maquette
                        // 25d, comme le nom de l'étudiant sur 25a.
                        'assetTag' => sprintf('<div class="fw-semibold text-ink">%s</div>', htmlspecialchars($laptop->getAssetTag())),
                        'deviceLabel' => trim(sprintf('%s %s', $laptop->getBrand() ?? '', $laptop->getModel() ?? '')) ?: '—',
                        'conditionName' => $condition?->getName(),
                        'conditionColor' => $condition?->getColor(),
                        'availability' => $availability,
                    ];
                },
                $rows,
            ),
        ]);
    }

    // 25a - a lean, action-oriented view (Étudiant/Ordinateur/Statut/Prêté le/Retour prévu +
    // "Enregistrer le retour"), unlike the full audit trail (prêté par, état au prêt/retour...)
    // that the per-laptop Historique page (historyData() below) still shows in full - that page
    // is the right place for the complete record, this list is for spotting what's overdue and
    // acting on it.
    //
    // "Afficher les prêts clôturés" is the same underlying onlyActive filter as before, just
    // read inverted from the request (unchecked by default = only current loans, matching the
    // design) - the shared datatable_controller.js only ever sends the checkbox's raw checked
    // state under the "onlyActive" key, so this reads that value as "includeReturned" instead of
    // renaming anything client-side.
    #[Route(path: '/laptops/loans/data', name: 'app_laptops_loans_data')]
    public function loansData(Request $request, LaptopLoanRepository $loanRepository, ProgramRepository $programRepository, LaptopStatusFormatter $statusFormatter, TranslatorInterface $translator): JsonResponse
    {
        [$draw, $start, $length, $search, $includeReturned] = $this->readLoansDataTableParams($request);
        $onlyActive = !$includeReturned;

        $total = $loanRepository->countAll(null, $onlyActive);
        $filteredTotal = '' !== $search ? $loanRepository->countAll($search, $onlyActive) : $total;
        $rows = $loanRepository->findPage($start, $length, '' !== $search ? $search : null, $onlyActive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(fn (LaptopLoan $loan): array => $this->loanListRow($loan, $programRepository, $statusFormatter, $translator), $rows),
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

    // Re-resolves and re-checks the submitted laptop id server-side rather than trusting it -
    // same reasoning as resolveActiveBorrower() below.
    private function resolveAvailableLaptop(LaptopRepository $repository, LaptopLoanRepository $loanRepository, mixed $laptopId): ?Laptop
    {
        if (!is_numeric($laptopId)) {
            return null;
        }

        $laptop = $repository->find((int) $laptopId);

        if (null === $laptop || null !== $laptop->getInactiveDate() || null !== $loanRepository->findActiveLoanForLaptop($laptop)) {
            return null;
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

    /** @return array{id: int, studentCell: string, computerCell: string, statusLabel: string, statusClass: string, lentAt: string, dueAt: string, canReturn: bool, returnUrl: ?string} */
    private function loanListRow(LaptopLoan $loan, ProgramRepository $programRepository, LaptopStatusFormatter $statusFormatter, TranslatorInterface $translator): array
    {
        $borrower = $loan->getBorrower();
        $program = $programRepository->findActiveForStudent($borrower);

        $studentCell = sprintf(
            '<span class="avatar avatar-sm me-2">%s</span><div class="d-inline-block align-middle"><div class="fw-semibold text-ink">%s</div><div class="text-secondary small">%s</div></div>',
            htmlspecialchars($this->initials($borrower)),
            htmlspecialchars($this->userLabel($borrower)),
            htmlspecialchars($program?->getDisplayShortName() ?? ''),
        );

        $computerCell = sprintf(
            '<div class="fw-semibold text-ink">%s</div><div class="text-secondary small">%s</div>',
            htmlspecialchars($loan->getLaptop()->getAssetTag()),
            htmlspecialchars(trim(sprintf('%s %s', $loan->getLaptop()->getBrand() ?? '', $loan->getLaptop()->getModel() ?? ''))),
        );

        $statusLabel = $statusFormatter->loanLabel($loan);
        if (!$loan->isReturned() && $loan->isOverdue()) {
            $daysOverdue = $loan->getDueAt()->diff(new \DateTimeImmutable())->days;
            $statusLabel = $translator->trans('laptopStatusOverdueDaysLabel', ['%days%' => $daysOverdue]);
        }

        return [
            'id' => $loan->getId(),
            'studentCell' => $studentCell,
            'computerCell' => $computerCell,
            'statusLabel' => $statusLabel,
            'statusClass' => $statusFormatter->loanCssClass($loan),
            'lentAt' => $loan->getLentAt()->format('d/m/Y'),
            'dueAt' => $loan->getDueAt()?->format('d/m/Y') ?? '—',
            'canReturn' => !$loan->isReturned(),
            'returnUrl' => !$loan->isReturned() ? $this->generateUrl('app_laptops_return', ['id' => $loan->getLaptop()->getId()]) : null,
        ];
    }

    private function initials(User $user): string
    {
        $name = $user->getDisplayName() ?? $user->getUsername();
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return mb_strtoupper(implode('', array_map(static fn (string $part): string => mb_substr($part, 0, 1), array_slice($parts, 0, 2))));
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

        return [$draw, $start, $length, $search, $includeInactive];
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readLoansDataTableParams(Request $request): array
    {
        [$draw, $start, $length, $search] = $this->readSimpleDataTableParams($request, withSearch: true);
        // Still read under the "onlyActive" query key - it's just the "Afficher les prêts
        // clôturés" checkbox's raw checked state, sent under that name by the shared
        // datatable_controller.js regardless of what the checkbox is labeled.
        $includeReturned = $request->query->getBoolean('onlyActive');

        return [$draw, $start, $length, $search, $includeReturned];
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

    // For fetch/AJAX actions (the sortable-reorder controller) - the token travels as a header.
    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    // For plain <form method="post"> submissions (25c's standalone per-row deactivate/reactivate
    // forms) - the token travels as a body field ("_token"), never as a header.
    private function assertValidFormToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}

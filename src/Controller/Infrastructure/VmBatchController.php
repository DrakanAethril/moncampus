<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Entity\GroupBatch;
use App\Entity\IpRange;
use App\Entity\Program;
use App\Entity\ProxmoxHost;
use App\Entity\User;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Enum\VmBatchItemStatus;
use App\Enum\VmBatchShape;
use App\Repository\GroupBatchRepository;
use App\Repository\IpRangeRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProxmoxHostRepository;
use App\Repository\UserRepository;
use App\Repository\VmBatchItemRepository;
use App\Repository\VmBatchRepository;
use App\Security\Voter\ProxmoxHostVoter;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxGuest;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxUnavailableException;
use App\Service\QueryValue;
use App\Service\VmBatch\BatchMemberResolver;
use App\Service\VmBatch\VmBatchExecutor;
use App\Service\VmBatch\VmBatchPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deploying one machine per student of a class.
 *
 * Only that shape is built. The design names three - per student, per group with individual
 * accounts, per group shared - and this ships the first, with the targeting that makes it useful:
 * within the class, the batch can be narrowed to the students following particular **options
 * and/or modalities**, so "one machine for each of SIO2" and "one for each SISR student of SIO2"
 * are both expressible.
 *
 * The plan is shown before anything is created, in full, because twenty-four machines is not a
 * thing to discover the shape of afterwards. And deployment runs in passes rather than all at once:
 * a browser request is what triggers it, twenty-four clones do not fit in one, and a batch that is
 * not atomic can be resumed - which is safe to press twice by construction.
 */
#[IsGranted('ROLE_ADMIN')]
class VmBatchController extends AbstractController
{
    use InfrastructureTrait;

    /**
     * Who may hold an account on a machine built here.
     *
     * Students and teachers, and deliberately nothing else: a tutor is somebody's employer and an
     * external account is a guest of the platform - neither has business holding a Unix login on a
     * classroom machine. Named once because the picker offers this list and the reading back
     * enforces it, and the two drifting apart is how a picker stops being a rule.
     */
    private const array ACCOUNT_ROLES = ['ROLE_STUDENT', 'ROLE_TEACHER'];

    /**
     * How long one deployment pass may run, in seconds. See deploy() for why the default is not
     * enough and why overrunning it is worse than being slow.
     */
    private const int PASS_TIME_LIMIT_SECONDS = 120;

    #[Route(path: '/infrastructure/batches', name: 'app_infrastructure_batches')]
    public function index(VmBatchRepository $batches, VmBatchItemRepository $items): Response
    {
        $rows = [];

        foreach ($batches->findOrdered(true) as $batch) {
            $rows[] = ['batch' => $batch, 'counts' => $items->countByStatus($batch)];
        }

        return $this->render('infrastructure/batches.html.twig', [
            'activeNav' => 'batches',
            'rows' => $rows,
        ]);
    }

    /**
     * The wizard, in one screen: the class and its filters, the machine, and the plan.
     *
     * A GET that shows a preview as soon as a class is chosen - "show me a result" is a GET, and it
     * makes the whole thing bookmarkable and refreshable without creating anything.
     */
    #[Route(path: '/infrastructure/batches/new', name: 'app_infrastructure_batches_new')]
    public function form(
        Request $request,
        ProgramRepository $programs,
        ProxmoxHostRepository $hosts,
        IpRangeRepository $ranges,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        BatchMemberResolver $resolver,
        VmBatchPlanner $planner,
        GroupBatchRepository $groupBatches,
        UserRepository $users,
        TranslatorInterface $translator,
    ): Response {
        $program = $this->programFrom($request, $programs);
        $host = $this->hostFrom($request, $hosts);

        $optionIds = QueryValue::intList($request, 'options');
        $modalityIds = QueryValue::intList($request, 'modalities');
        $namePattern = QueryValue::trimmed($request, 'namePattern') ?: 'tp-{index}';

        $shape = VmBatchShape::tryFrom(QueryValue::string($request, 'shape')) ?? VmBatchShape::PerStudent;
        $targetableGroupBatches = $this->targetableGroupBatches($program, $groupBatches);
        $groupBatch = $this->groupBatchFrom($request, $targetableGroupBatches);

        $chosenUsers = VmBatchShape::ForAccounts === $shape
            ? $this->chosenUsers(QueryValue::intList($request, 'users'), $users)
            : [];

        // No set named yet is not an empty set: the plan below simply has nothing to lay out, and
        // the screen asks for the set rather than showing zero machines as though there were none.
        $groups = match ($shape) {
            VmBatchShape::PerGroup => null !== $groupBatch
                ? $resolver->forGroupBatch($groupBatch, $translator->trans('programToolsGroupTitleTemplateLabel'))
                : [],
            VmBatchShape::ForAccounts => $resolver->forUsers($chosenUsers),
            VmBatchShape::PerStudent => [],
        };

        $members = null !== $program && VmBatchShape::PerStudent === $shape ? $resolver->forProgram($program) : [];
        $selected = $planner->select($members, $optionIds, $modalityIds);

        $templates = [];
        $nodes = [];
        $usedVmids = [];
        $failure = null;

        if (null !== $host) {
            try {
                $client = $clientFactory->operate($host);
                $guests = $inventory->guests($client);
                $templates = $inventory->templates($guests);
                $nodes = $inventory->nodes($client);
                $usedVmids = array_map(static fn (ProxmoxGuest $guest): int => $guest->vmid, $guests);
            } catch (ProxmoxUnavailableException $exception) {
                $failure = $exception->getMessage();
            }
        }

        // Shown in full before anything is created: twenty-four machines is not a shape to discover
        // afterwards.
        $plan = [];
        $groupPlan = [];

        if (null !== $host) {
            $min = $host->getVmidMin() ?? 100;
            $max = $host->getVmidMax() ?? 999999;

            if ($shape->isMultiAccount()) {
                $groupPlan = $planner->planGroups($groups, $namePattern, $min, $max, $usedVmids);
            } else {
                $plan = $planner->plan($selected, $namePattern, $min, $max, $usedVmids);
            }
        }

        // How many machines the shape asks for, whichever shape it is - the "not enough VMIDs"
        // notice below has to count the same things on both sides.
        $wanted = $shape->isMultiAccount()
            ? \count(array_filter($groups, static fn (array $group): bool => [] !== $group['members']))
            : \count($selected);
        $planned = $shape->isMultiAccount() ? \count($groupPlan) : \count($plan);

        return $this->render('infrastructure/batch_new.html.twig', [
            'activeNav' => 'batches',
            'programs' => $programs->findBy([], ['shortName' => 'ASC']),
            'hosts' => $hosts->findOrdered(),
            'ranges' => null !== $host ? $ranges->findActiveForHost($host) : [],
            'program' => $program,
            'host' => $host,
            'templates' => $templates,
            'nodes' => $nodes,
            'members' => $members,
            'selected' => $selected,
            'plan' => $plan,
            'groupPlan' => $groupPlan,
            'shape' => $shape,
            'shapes' => VmBatchShape::cases(),
            'targetableGroupBatches' => $targetableGroupBatches,
            'groupBatch' => $groupBatch,
            'chosenUsers' => $chosenUsers,
            'namePattern' => $namePattern,
            'filters' => ['options' => $optionIds, 'modalities' => $modalityIds],
            'failure' => $failure,
            // Not enough VMIDs for the whole class is a fact worth stating before the button, not
            // after: it means some students would silently get nothing.
            'shortBy' => max(0, $wanted - $planned),
        ]);
    }

    #[Route(path: '/infrastructure/batches/create', name: 'app_infrastructure_batches_create', methods: ['POST'])]
    public function create(
        Request $request,
        ProgramRepository $programs,
        ProxmoxHostRepository $hosts,
        IpRangeRepository $ranges,
        BatchMemberResolver $resolver,
        VmBatchPlanner $planner,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        GroupBatchRepository $groupBatches,
        UserRepository $users,
        TranslatorInterface $translator,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('infrastructure_action', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $program = $programs->find($request->request->getInt('programId'));
        $host = $hosts->find($request->request->getInt('hostId'));
        $range = $ranges->find($request->request->getInt('rangeId'));

        if (!$program instanceof Program || !$host instanceof ProxmoxHost || !$range instanceof IpRange) {
            $this->addFlash('error', 'vmBatchIncompleteMessage');

            return $this->redirectToRoute('app_infrastructure_batches_new');
        }

        $this->denyAccessUnlessGranted(ProxmoxHostVoter::PROVISION, $host);

        $shape = VmBatchShape::tryFrom((string) $request->request->get('shape', '')) ?? VmBatchShape::PerStudent;
        // Re-read against what this admin may actually target, never trusted from the posted id -
        // the picker is a convenience, the same posture the wiki side takes.
        $groupBatch = $this->matchGroupBatch(
            $request->request->getInt('groupBatchId'),
            $this->targetableGroupBatches($program, $groupBatches),
        );

        if (VmBatchShape::PerGroup === $shape && null === $groupBatch) {
            $this->addFlash('error', 'vmBatchGroupBatchUnavailableMessage');

            return $this->redirectToRoute('app_infrastructure_batches_new');
        }

        // Re-read from the ids and re-filtered on the roles, exactly like the set above: a picker
        // is a convenience, never the authority on who may end up with an account on a machine.
        $chosenUsers = VmBatchShape::ForAccounts === $shape
            ? $this->chosenUsers(array_map(intval(...), (array) $request->request->all('users')), $users)
            : [];

        if (VmBatchShape::ForAccounts === $shape && [] === $chosenUsers) {
            $this->addFlash('error', 'vmBatchNoChosenAccountMessage');

            return $this->redirectToRoute('app_infrastructure_batches_new');
        }

        $batch = new VmBatch(
            (string) $request->request->get('label', ''),
            $program,
            $host,
            $range,
            $request->request->getInt('templateVmid'),
            (string) $request->request->get('node', ''),
        );
        $batch->setCreatedBy($this->currentUser());
        $batch->setShape($shape);
        $batch->setGroupBatch(VmBatchShape::PerGroup === $shape ? $groupBatch : null);
        $batch->setCores($request->request->getInt('cores', 2));
        $batch->setMemoryMib($request->request->getInt('memoryMib', 2048));
        $batch->setDiskGib($request->request->getInt('diskGib', 16));
        $batch->setStorage((string) $request->request->get('storage', 'local-lvm'));
        $batch->setLinkedClone(null !== $request->request->get('linkedClone'));
        $batch->setNamePattern((string) $request->request->get('namePattern', 'tp-{index}'));
        $batch->setPostInstallScript($this->nullIfBlank((string) $request->request->get('postInstallScript', '')));

        $expiresAt = (string) $request->request->get('expiresAt', '');
        if ('' !== $expiresAt) {
            $batch->setExpiresAt(new \DateTimeImmutable($expiresAt));
        }

        $optionIds = array_map(intval(...), (array) $request->request->all('options'));
        $modalityIds = array_map(intval(...), (array) $request->request->all('modalities'));

        foreach ($program->getOptions() as $option) {
            if (\in_array($option->getId(), $optionIds, true)) {
                $batch->addOption($option);
            }
        }

        foreach ($program->getModalities() as $modality) {
            if (\in_array($modality->getId(), $modalityIds, true)) {
                $batch->addModality($modality);
            }
        }

        $entityManager->persist($batch);

        $usedVmids = [];
        try {
            $usedVmids = array_map(
                static fn (ProxmoxGuest $guest): int => $guest->vmid,
                $inventory->guests($clientFactory->operate($host)),
            );
        } catch (ProxmoxUnavailableException) {
            // Planned against what is recorded rather than refusing outright: the executor checks
            // each VMID again at creation time, and the hypervisor has the last word anyway.
        }

        $min = $host->getVmidMin() ?? 100;
        $max = $host->getVmidMax() ?? 999999;

        if ($shape->isMultiAccount()) {
            // Non-null on the PerGroup side: the guard above returned when that shape named no set.
            $groups = VmBatchShape::ForAccounts === $shape
                ? $resolver->forUsers($chosenUsers)
                : $resolver->forGroupBatch($groupBatch, $translator->trans('programToolsGroupTitleTemplateLabel'));
            $rows = array_map(
                // One machine per group: the group's own label stands where a student's name would,
                // its slug where their login would, and the members travel as the item's snapshot.
                static fn (array $row): array => [
                    'studentLabel' => $row['groupLabel'],
                    'guestName' => $row['guestName'],
                    'login' => $row['slug'],
                    'position' => $row['position'],
                    'vmid' => $row['vmid'],
                    'userId' => 0,
                    'members' => $row['members'],
                ],
                $planner->planGroups($groups, $batch->getNamePattern(), $min, $max, $usedVmids),
            );
        } else {
            $members = $planner->select($resolver->forProgram($program), $optionIds, $modalityIds);
            $rows = array_map(
                static fn (array $row): array => $row + ['members' => []],
                $planner->plan($members, $batch->getNamePattern(), $min, $max, $usedVmids),
            );
        }

        foreach ($rows as $row) {
            $item = new VmBatchItem($batch, $row['studentLabel'], $row['guestName'], $row['login'], $row['position'], $row['members']);
            $item->setVmid($row['vmid']);
            $item->setNode($batch->getNode());
            $item->setStudent($this->studentWithId($program, $row['userId']));
            $batch->addItem($item);
            $entityManager->persist($item);
        }

        $entityManager->flush();

        $this->addFlash('success', 'vmBatchCreatedFlashMessage');

        return $this->redirectToRoute('app_infrastructure_batch', ['id' => $batch->getId()]);
    }

    #[Route(path: '/infrastructure/batches/{id}', name: 'app_infrastructure_batch', requirements: ['id' => '\d+'])]
    public function show(VmBatchRepository $batches, VmBatchItemRepository $items, int $id): Response
    {
        $batch = $batches->find($id) ?? throw $this->createNotFoundException();

        return $this->render('infrastructure/batch.html.twig', [
            'activeNav' => 'batches',
            'batch' => $batch,
            'counts' => $items->countByStatus($batch),
            'remaining' => \count($items->findResumable($batch)),
        ]);
    }

    /**
     * One pass of the deployment. Answers what it did and what is left, so the screen can offer to
     * press again - which is safe by construction, since only the outstanding items are attempted.
     *
     * **A pass needs more than the default thirty seconds, and asking for it is not optional.** One
     * step of one machine chains up to five bounded Proxmox calls, and the accounts step opens an
     * SSH session and runs the batch's post-installation script inside the guest. A
     * MaxExecutionTimeError is fatal and not catchable: the pass would write nothing at all - no
     * status, no installation log line - and the screen would show a bare warning for a machine
     * that had in fact been cloned. Two minutes is what makes the slow cases *recorded* failures
     * rather than silent ones.
     */
    #[Route(path: '/infrastructure/batches/{id}/deploy', name: 'app_infrastructure_batch_deploy', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deploy(Request $request, VmBatchRepository $batches, VmBatchExecutor $executor, int $id): JsonResponse
    {
        $this->assertValidInfrastructureToken($request);
        set_time_limit(self::PASS_TIME_LIMIT_SECONDS);

        $batch = $batches->find($id) ?? throw $this->createNotFoundException();
        $host = $batch->getHost();

        if (null === $host) {
            return $this->json(['ok' => false, 'message' => 'vmBatchIncompleteMessage']);
        }

        $this->denyAccessUnlessGranted(ProxmoxHostVoter::PROVISION, $host);

        return $this->json(['ok' => true, ...$executor->run($batch, $this->currentUser())]);
    }

    /**
     * Takes a machine out of a batch before it exists.
     *
     * Only a planned one: from the moment a clone has been asked for, the row is the only record of
     * a machine that exists on the hypervisor - its address, its task, its accounts - and deleting
     * it would leave that machine with nothing pointing at it. **Nothing here ever destroys a
     * machine**; that is not something this application does at all.
     */
    #[Route(path: '/infrastructure/batches/{id}/items/{itemId}/remove', name: 'app_infrastructure_batch_item_remove', requirements: ['id' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function removeItem(Request $request, VmBatchRepository $batches, VmBatchItemRepository $items, EntityManagerInterface $entityManager, int $id, int $itemId): Response
    {
        // Read from the BODY, not the header: these two are ordinary form posts, while
        // assertValidInfrastructureToken() serves the fetch-driven actions of this area.
        if (!$this->isCsrfTokenValid('infrastructure_action', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $batch = $batches->find($id) ?? throw $this->createNotFoundException();
        $item = $items->find($itemId);

        // Its own batch, checked rather than trusted from the URL.
        if (null === $item || $item->getBatch()?->getId() !== $batch->getId()) {
            throw $this->createNotFoundException();
        }

        if (VmBatchItemStatus::Planned !== $item->getStatus()) {
            $this->addFlash('error', 'vmBatchItemNotRemovableFlashMessage');

            return $this->redirectToRoute('app_infrastructure_batch', ['id' => $id]);
        }

        $entityManager->remove($item);
        $entityManager->flush();
        $this->addFlash('success', 'vmBatchItemRemovedFlashMessage');

        return $this->redirectToRoute('app_infrastructure_batch', ['id' => $id]);
    }

    /**
     * Removes the batch itself - the plan and its record, never the machines.
     *
     * The machines it created go on running on the hypervisor, untouched and unreachable from here
     * afterwards; the screen says so before asking. The accounts already placed keep their rows and
     * simply lose the batch they belonged to (`ON DELETE SET NULL`), because they describe accounts
     * that exist on real machines and outlive the plan that put them there.
     */
    #[Route(path: '/infrastructure/batches/{id}/remove', name: 'app_infrastructure_batch_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(Request $request, VmBatchRepository $batches, EntityManagerInterface $entityManager, int $id): Response
    {
        // Read from the BODY, not the header: these two are ordinary form posts, while
        // assertValidInfrastructureToken() serves the fetch-driven actions of this area.
        if (!$this->isCsrfTokenValid('infrastructure_action', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $batch = $batches->find($id) ?? throw $this->createNotFoundException();
        $host = $batch->getHost();

        if (null !== $host) {
            $this->denyAccessUnlessGranted(ProxmoxHostVoter::PROVISION, $host);
        }

        $entityManager->remove($batch);
        $entityManager->flush();
        $this->addFlash('success', 'vmBatchRemovedFlashMessage');

        return $this->redirectToRoute('app_infrastructure_batches');
    }

    private function programFrom(Request $request, ProgramRepository $programs): ?Program
    {
        $id = QueryValue::nullableInt($request, 'programId');

        return null !== $id ? $programs->find($id) : null;
    }

    private function hostFrom(Request $request, ProxmoxHostRepository $hosts): ?ProxmoxHost
    {
        $id = QueryValue::nullableInt($request, 'hostId');

        return null !== $id ? $hosts->find($id) : ($hosts->findOrdered()[0] ?? null);
    }

    private function studentWithId(Program $program, int $userId): ?User
    {
        foreach ($program->getStudents() as $student) {
            if ($student->getId() === $userId) {
                return $student;
            }
        }

        return null;
    }

    /**
     * The saved sets of groups this admin may turn into machines, for the class being planned.
     *
     * The rule is the group tool's own and gets no override here: one sees the sets one saved, plus
     * the sets a colleague shared. An admin who teaches nothing therefore targets nothing, and the
     * wizard says so rather than offering every set in the school - being an admin says what one may
     * *deploy*, never which groups of which class are the right ones.
     *
     * @return list<GroupBatch>
     */
    /**
     * The people picker of the ForAccounts shape - tomselect + ajax, per the repository's rule that
     * picking Users always goes through one.
     *
     * Students and teachers, active only. Nobody else is offered because nobody else is what these
     * machines are for: a tutor or an external account has no business holding a Unix login on a
     * classroom machine, and offering them would make that mistake one click away.
     */
    #[Route(path: '/infrastructure/batches/users/search', name: 'app_infrastructure_batches_user_search', methods: ['GET'])]
    public function userSearch(Request $request, UserRepository $users): JsonResponse
    {
        $limit = 20;
        $candidates = \array_slice($users->findActiveMatchingAnyRole(self::ACCOUNT_ROLES, [], QueryValue::trimmed($request, 'q')), 0, $limit);

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => \sprintf('%s (%s)', $user->getDisplayName() ?? $user->getUsername(), $user->getUsername()),
            ], $candidates),
            'pagination' => ['more' => \count($candidates) === $limit],
        ]);
    }

    /**
     * The picked accounts, read back from ids.
     *
     * Filtered against the same roles the picker offers rather than trusted: the ids arrive in a
     * query string, and « one machine for these three » must not become a way to put a Unix account
     * on a classroom machine for somebody the screen would never have proposed.
     *
     * @param list<int> $ids
     *
     * @return list<User>
     */
    private function chosenUsers(array $ids, UserRepository $users): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_values(array_filter(
            $users->findBy(['id' => $ids]),
            static fn (User $user): bool => null === $user->getInactiveDate() && [] !== array_intersect(self::ACCOUNT_ROLES, $user->getRoles()),
        ));
    }

    private function targetableGroupBatches(?Program $program, GroupBatchRepository $groupBatches): array
    {
        if (!$program instanceof Program) {
            return [];
        }

        return $groupBatches->findAllReadableForTeacherAndPrograms($this->currentUser(), [$program]);
    }

    /**
     * @param list<GroupBatch> $targetable
     */
    private function groupBatchFrom(Request $request, array $targetable): ?GroupBatch
    {
        return $this->matchGroupBatch(QueryValue::int($request, 'groupBatchId'), $targetable);
    }

    /**
     * @param list<GroupBatch> $targetable
     */
    private function matchGroupBatch(int $id, array $targetable): ?GroupBatch
    {
        if ($id <= 0) {
            return null;
        }

        foreach ($targetable as $candidate) {
            if ($candidate->getId() === $id) {
                return $candidate;
            }
        }

        return null;
    }

    private function nullIfBlank(string $value): ?string
    {
        return '' !== trim($value) ? $value : null;
    }
}

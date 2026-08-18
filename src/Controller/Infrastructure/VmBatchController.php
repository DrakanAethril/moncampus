<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Entity\IpRange;
use App\Entity\Program;
use App\Entity\ProxmoxHost;
use App\Entity\User;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Repository\IpRangeRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProxmoxHostRepository;
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
    ): Response {
        $program = $this->programFrom($request, $programs);
        $host = $this->hostFrom($request, $hosts);

        $optionIds = QueryValue::intList($request, 'options');
        $modalityIds = QueryValue::intList($request, 'modalities');
        $namePattern = QueryValue::trimmed($request, 'namePattern') ?: 'tp-{index}';

        $members = null !== $program ? $resolver->forProgram($program) : [];
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
        $plan = null !== $host
            ? $planner->plan($selected, $namePattern, $host->getVmidMin() ?? 100, $host->getVmidMax() ?? 999999, $usedVmids)
            : [];

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
            'namePattern' => $namePattern,
            'filters' => ['options' => $optionIds, 'modalities' => $modalityIds],
            'failure' => $failure,
            // Not enough VMIDs for the whole class is a fact worth stating before the button, not
            // after: it means some students would silently get nothing.
            'shortBy' => max(0, \count($selected) - \count($plan)),
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

        $batch = new VmBatch(
            (string) $request->request->get('label', ''),
            $program,
            $host,
            $range,
            $request->request->getInt('templateVmid'),
            (string) $request->request->get('node', ''),
        );
        $batch->setCreatedBy($this->currentUser());
        $batch->setCores($request->request->getInt('cores', 2));
        $batch->setMemoryMib($request->request->getInt('memoryMib', 2048));
        $batch->setDiskGib($request->request->getInt('diskGib', 16));
        $batch->setStorage((string) $request->request->get('storage', 'local-lvm'));
        $batch->setLinkedClone(null !== $request->request->get('linkedClone'));
        $batch->setGrantSudo(null !== $request->request->get('grantSudo'));
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

        $members = $planner->select($resolver->forProgram($program), $optionIds, $modalityIds);
        $rows = $planner->plan($members, $batch->getNamePattern(), $host->getVmidMin() ?? 100, $host->getVmidMax() ?? 999999, $usedVmids);

        foreach ($rows as $row) {
            $item = new VmBatchItem($batch, $row['studentLabel'], $row['guestName'], $row['login'], $row['position']);
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
     */
    #[Route(path: '/infrastructure/batches/{id}/deploy', name: 'app_infrastructure_batch_deploy', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deploy(Request $request, VmBatchRepository $batches, VmBatchExecutor $executor, int $id): JsonResponse
    {
        $this->assertValidInfrastructureToken($request);

        $batch = $batches->find($id) ?? throw $this->createNotFoundException();
        $host = $batch->getHost();

        if (null === $host) {
            return $this->json(['ok' => false, 'message' => 'vmBatchIncompleteMessage']);
        }

        $this->denyAccessUnlessGranted(ProxmoxHostVoter::PROVISION, $host);

        return $this->json(['ok' => true, ...$executor->run($batch, $this->currentUser())]);
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

    private function nullIfBlank(string $value): ?string
    {
        return '' !== trim($value) ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Entity\IpAllocation;
use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use App\Repository\IpAllocationRepository;
use App\Repository\IpRangeRepository;
use App\Repository\ProxmoxHostRepository;
use App\Security\Voter\ProxmoxHostVoter;
use App\Service\JsonRequestPayload;
use App\Service\Network\AddressUnavailableException;
use App\Service\Network\GuestNetworkConfigurator;
use App\Service\Network\IpAllocator;
use App\Service\Network\IpRangeCalculator;
use App\Service\Network\RangeExhaustedException;
use App\Service\Proxmox\GuestCreationRequest;
use App\Service\Proxmox\GuestCreator;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxGuest;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxScope;
use App\Service\Proxmox\ProxmoxScopeGuard;
use App\Service\Proxmox\ProxmoxUnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creating one machine, in three numbered steps - and the numbering carries information rather than
 * decoration: **the source decides what step 2 is able to offer**. A clone of a cloud-init template
 * can be given its name and address before it first boots; an installation from an ISO cannot be
 * configured at all, and step 2 changes shape to say so instead of showing fields that would do
 * nothing.
 *
 * The address is **reserved at step 2**, not at the end. Two administrators filling in the wizard at
 * the same time would otherwise both be shown "the next free one is .57" and both create a machine
 * with it. The reservation is what makes the offer true; an abandoned wizard's reservation is freed
 * by `app:proxmox:scan-addresses` after half an hour.
 *
 * Rate-limited, because this is the one screen in the area that *creates* things and a mistyped
 * loop somewhere should not be able to fill a hypervisor.
 */
#[IsGranted('ROLE_ADMIN')]
class GuestCreationController extends AbstractController
{
    use InfrastructureTrait;

    private const string SESSION_KEY = 'infrastructure.guest_creation';

    public function __construct(
        private readonly TranslatorInterface $translator,
        #[Target('proxmox_guest_creation')]
        private readonly RateLimiterFactoryInterface $creationLimiter,
    ) {
    }

    /**
     * Step 1 - the source. Templates come free with the machine list (the rows of
     * `/cluster/resources` flagged `template`); ISOs need their storages read.
     */
    #[Route(path: '/infrastructure/hosts/{id}/guests/new', name: 'app_infrastructure_guests_new', requirements: ['id' => '\d+'])]
    public function source(
        Request $request,
        ProxmoxHostRepository $repository,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        int $id,
    ): Response {
        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::PROVISION, $host);

        $templates = [];
        $isos = [];
        $failure = null;

        try {
            $client = $clientFactory->operate($host);
            $nodes = $inventory->nodes($client);
            $templates = $inventory->templates($inventory->guests($client));
            $isos = $inventory->isoImages($client, $nodes);
        } catch (ProxmoxUnavailableException $exception) {
            $failure = $exception->getMessage();
        }

        return $this->render('infrastructure/guest_new_source.html.twig', [
            'activeNav' => 'guests',
            'host' => $host,
            'templates' => $templates,
            'isos' => $isos,
            'failure' => $failure,
            'draft' => $this->draft($request, $host),
        ]);
    }

    /**
     * Step 2 - the machine. This is where the address is reserved, so arriving here is what makes
     * the offered address true rather than a guess.
     */
    #[Route(path: '/infrastructure/hosts/{id}/guests/new/machine', name: 'app_infrastructure_guests_new_machine', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function machine(
        Request $request,
        ProxmoxHostRepository $repository,
        IpRangeRepository $ranges,
        IpAllocationRepository $allocations,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        IpAllocator $allocator,
        IpRangeCalculator $calculator,
        GuestNetworkConfigurator $configurator,
        int $id,
    ): Response {
        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::PROVISION, $host);

        $draft = $this->draft($request, $host);

        if ($request->isMethod('POST')) {
            $draft['sourceVmid'] = $request->request->getInt('sourceVmid') ?: null;
            $draft['isoVolumeId'] = (string) $request->request->get('isoVolumeId', '');
            $draft['linkedClone'] = null !== $request->request->get('linkedClone');
            $this->saveDraft($request, $draft);
        }

        if (null === $draft['sourceVmid'] && '' === (string) $draft['isoVolumeId']) {
            return $this->redirectToRoute('app_infrastructure_guests_new', ['id' => $id]);
        }

        $nodes = [];
        $storages = [];
        $suggestedVmid = null;
        $template = null;
        $failure = null;

        try {
            $client = $clientFactory->operate($host);
            $nodes = $inventory->nodes($client);
            $guests = $inventory->guests($client);
            $suggestedVmid = $this->suggestVmid($host, $guests, $client->get('/cluster/nextid')->scalar());
            $template = $this->findTemplate($guests, $draft['sourceVmid']);
            $storages = $nodes ? $inventory->storages($client, $nodes[0]->name) : [];
        } catch (ProxmoxUnavailableException $exception) {
            $failure = $exception->getMessage();
        }

        $availableRanges = $ranges->findActiveForHost($host);

        return $this->render('infrastructure/guest_new_machine.html.twig', [
            'activeNav' => 'guests',
            'host' => $host,
            'draft' => $draft,
            'template' => $template,
            'nodes' => $nodes,
            'storages' => $storages,
            'ranges' => $availableRanges,
            'rangeSummaries' => $this->rangeSummaries($availableRanges, $allocations, $calculator),
            'suggestedVmid' => $suggestedVmid,
            // From the template's own name when there is one - an ISO install has none to borrow.
            'suggestedHostname' => $configurator->suggestHostname(null !== $template ? $template->name : 'vm'),
            'scope' => ProxmoxScope::fromHost($host),
            'failure' => $failure,
        ]);
    }

    /**
     * Step 3 - the summary, and the creation itself. The address is reserved here, immediately
     * before the call, and released the moment anything fails.
     */
    #[Route(path: '/infrastructure/hosts/{id}/guests/new/confirm', name: 'app_infrastructure_guests_new_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirm(
        Request $request,
        ProxmoxHostRepository $repository,
        IpRangeRepository $ranges,
        IpAllocator $allocator,
        GuestCreator $creator,
        ProxmoxScopeGuard $scopeGuard,
        int $id,
    ): Response {
        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::PROVISION, $host);

        if (!$this->isCsrfTokenValid('infrastructure_action', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // One machine at a time is fine; a loop that fills a hypervisor is not.
        if (!$this->creationLimiter->create($this->currentUser()->getUserIdentifier())->consume()->isAccepted()) {
            $this->addFlash('error', 'proxmoxCreationRateLimitedMessage');

            return $this->redirectToRoute('app_infrastructure_guests_new_machine', ['id' => $id]);
        }

        $draft = $this->draft($request, $host);
        $range = $ranges->find($request->request->getInt('rangeId'));

        if (!$range instanceof IpRange) {
            $this->addFlash('error', 'ipRangeRequiredMessage');

            return $this->redirectToRoute('app_infrastructure_guests_new_machine', ['id' => $id]);
        }

        try {
            $allocation = $this->reserve($request, $allocator, $range);
        } catch (RangeExhaustedException|AddressUnavailableException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_infrastructure_guests_new_machine', ['id' => $id]);
        }

        $creationRequest = new GuestCreationRequest(
            hostname: (string) $request->request->get('hostname', ''),
            vmid: $request->request->getInt('vmid'),
            node: (string) $request->request->get('node', ''),
            cores: $request->request->getInt('cores', 2),
            memoryMib: $request->request->getInt('memoryMib', 2048),
            diskGib: $request->request->getInt('diskGib', 16),
            storage: (string) $request->request->get('storage', ''),
            range: $range,
            ip: $allocation->getIp(),
            sourceVmid: $draft['sourceVmid'],
            linkedClone: (bool) $draft['linkedClone'],
            isoVolumeId: '' !== (string) $draft['isoVolumeId'] ? (string) $draft['isoVolumeId'] : null,
            startAfterCreation: null !== $request->request->get('startAfterCreation'),
            postInstallScript: null,
        );

        try {
            $operation = $creator->create($host, $creationRequest, $allocation, $this->currentUser());
        } catch (ProxmoxUnavailableException $exception) {
            // The allocation has already been released by the creator - the range must not lose an
            // address to every failed attempt.
            $this->addFlash('error', $this->translator->trans($exception->getMessage()));

            return $this->redirectToRoute('app_infrastructure_guests_new_machine', ['id' => $id]);
        }

        $request->getSession()->remove(self::SESSION_KEY);
        $this->addFlash('success', 'proxmoxGuestCreatedFlashMessage');

        return $this->render('infrastructure/guest_new_done.html.twig', [
            'activeNav' => 'guests',
            'host' => $host,
            'request' => $creationRequest,
            'operation' => $operation,
            'allocation' => $allocation,
        ]);
    }

    /** @throws RangeExhaustedException|AddressUnavailableException */
    private function reserve(Request $request, IpAllocator $allocator, IpRange $range): IpAllocation
    {
        $chosen = trim((string) $request->request->get('ip', ''));
        $hostname = (string) $request->request->get('hostname', '');

        return '' !== $chosen
            ? $allocator->reserve($range, $chosen, $hostname)
            : $allocator->reserveNext($range, hostname: $hostname);
    }

    /**
     * @param list<IpRange> $ranges
     *
     * @return array<int, array{free: int, capacity: int, next: string|null}>
     */
    private function rangeSummaries(array $ranges, IpAllocationRepository $allocations, IpRangeCalculator $calculator): array
    {
        $summaries = [];

        foreach ($ranges as $range) {
            $taken = $allocations->findLiveAddresses($range);
            $summaries[$range->getId() ?? 0] = [
                'free' => $calculator->freeCount($range->getFirstUsable(), $range->getLastUsable(), $taken),
                'capacity' => $calculator->capacity($range->getFirstUsable(), $range->getLastUsable()),
                // Offered rather than reserved: nothing is taken until the summary is confirmed.
                'next' => $calculator->nextFree($range->getFirstUsable(), $range->getLastUsable(), $taken),
            ];
        }

        return $summaries;
    }

    /** @param list<ProxmoxGuest> $guests */
    private function findTemplate(array $guests, ?int $vmid): ?ProxmoxGuest
    {
        if (null === $vmid) {
            return null;
        }

        foreach ($guests as $guest) {
            if ($guest->vmid === $vmid) {
                return $guest;
            }
        }

        return null;
    }

    /**
     * Proxmox's own suggestion, nudged into the declared VMID window: `/cluster/nextid` knows what
     * is free on the cluster but nothing of this host's perimeter, and offering a number the scope
     * guard will refuse would be a strange way to start.
     *
     * @param list<ProxmoxGuest> $guests
     */
    private function suggestVmid(ProxmoxHost $host, array $guests, ?string $nextId): ?int
    {
        $used = array_map(static fn (ProxmoxGuest $guest): int => $guest->vmid, $guests);
        $min = $host->getVmidMin();
        $max = $host->getVmidMax();

        if (null === $min) {
            return is_numeric($nextId) ? (int) $nextId : null;
        }

        for ($candidate = $min; $candidate <= ($max ?? $min + 999); ++$candidate) {
            if (!\in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array{sourceVmid: int|null, isoVolumeId: string, linkedClone: bool} */
    private function draft(Request $request, ProxmoxHost $host): array
    {
        $stored = $request->getSession()->get(self::SESSION_KEY);
        // The same typed reading a JSON body gets: a session entry is only as trustworthy as
        // whatever put it there, and this one carries a VMID that ends up in a URL.
        $payload = JsonRequestPayload::fromArray(\is_array($stored) ? $stored : []);

        return [
            'sourceVmid' => $payload->int('sourceVmid'),
            'isoVolumeId' => $payload->string('isoVolumeId'),
            'linkedClone' => $payload->bool('linkedClone'),
        ];
    }

    /** @param array{sourceVmid: int|null, isoVolumeId: string, linkedClone: bool} $draft */
    private function saveDraft(Request $request, array $draft): void
    {
        $request->getSession()->set(self::SESSION_KEY, $draft);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Attribute\RequiresFeature;
use App\Entity\ProxmoxHost;
use App\Enum\Feature;
use App\Enum\ProxmoxAction;
use App\Repository\IpAllocationRepository;
use App\Repository\ProxmoxHostRepository;
use App\Repository\ProxmoxOperationRepository;
use App\Repository\VmBatchItemRepository;
use App\Repository\VmBatchRepository;
use App\Security\Voter\ProxmoxHostVoter;
use App\Service\Proxmox\GuestPowerRunner;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxGuest;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxScope;
use App\Service\Proxmox\ProxmoxScopeGuard;
use App\Service\Proxmox\ProxmoxUnavailableException;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The machines of every declared host: the list, and the four power actions.
 *
 * Unlike every other list screen in this application, this one **reads the hypervisors as it
 * renders**. That is not an oversight of the "never probe at display" rule, it is the other side of
 * it: the rule is about the *hosts screen*, where the slowest host would hold up the badges of the
 * rest. Here the machines are what the screen is about, and showing a stale list would be worse
 * than showing an error - somebody is about to press "stop" on one of these rows. A host that does
 * not answer is named in a note of its own and the others are still listed: one hypervisor being
 * down must not take the whole fleet off the screen.
 *
 * It used to be one screen per host, reached through an « Aller à… » jump box beside the tabs. The
 * host is a filter now, in the same bar as the rest and built the same way as the operations
 * journal's - a jump list is a poor filter, and it made this the only screen you could not simply
 * click to.
 *
 * Templates are not listed. They are what the Images screen is about, they are the only rows no
 * action ever applies to, and leaving them here made half the fleet's rows inert.
 *
 * Nothing about a machine is stored. A guest created by hand in Proxmox appears here with nothing
 * to synchronise; one destroyed there simply stops being listed.
 *
 * There is no delete action on any row, in any state. That is settled, and the machines list is
 * deliberately not where it gets re-explained line by line - the host form says it once, at the
 * moment the question arises.
 */
#[IsGranted('ROLE_ADMIN')]
#[RequiresFeature(Feature::Infrastructure)]
class GuestController extends AbstractController
{
    use InfrastructureTrait;

    /** Fifty rows a page, the same as the operations journal - a fleet is read a class at a time. */
    private const int PAGE_SIZE = 50;

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route(path: '/infrastructure/guests', name: 'app_infrastructure_guests')]
    public function index(
        Request $request,
        ProxmoxHostRepository $hosts,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        ProxmoxScopeGuard $scopeGuard,
        ProxmoxOperationRepository $operations,
        IpAllocationRepository $allocations,
        VmBatchRepository $batches,
        VmBatchItemRepository $batchItems,
    ): Response {
        // Every filter through QueryValue: a filter bar whose "Tous" option carries value="" sends
        // `?host=` as a matter of course, and InputBag::getInt() answers 400 to exactly that.
        $search = QueryValue::trimmed($request, 'q');
        $hostId = QueryValue::nullableInt($request, 'host');
        $status = QueryValue::trimmed($request, 'status');
        $batchId = QueryValue::nullableInt($request, 'batch');
        $page = max(1, QueryValue::int($request, 'page', 1));

        $declared = $hosts->findOrdered();
        // The host filter narrows what is read, not just what is shown: an unselected host is one
        // fewer hypervisor to wait for, which is the whole reason to offer the filter on a screen
        // that probes as it renders.
        $selected = null !== $hostId
            ? array_values(array_filter($declared, static fn (ProxmoxHost $host): bool => $host->getId() === $hostId))
            : $declared;

        // vmid => batch, per host: a VMID is only unique within a cluster, so the map is two deep.
        $batchByMachine = $batchItems->findBatchesByHostAndVmid();
        $liveOperations = $operations->findUnsettledByHostAndVmid();

        $rows = [];
        $failures = [];
        $totalCount = 0;

        foreach ($selected as $host) {
            $this->denyAccessUnlessGranted(ProxmoxHostVoter::VIEW, $host);

            try {
                $guests = $inventory->machines($inventory->guests($clientFactory->operate($host)));
            } catch (ProxmoxUnavailableException $exception) {
                $failures[] = ['host' => $host, 'message' => $exception->getMessage()];

                continue;
            }

            $scope = ProxmoxScope::fromHost($host);
            $hostKey = (int) $host->getId();
            $canOperate = $this->isGranted(ProxmoxHostVoter::OPERATE, $host);
            $totalCount += \count($guests);

            foreach ($guests as $guest) {
                $batch = $batchByMachine[$hostKey][$guest->vmid] ?? null;

                if (!$this->matchesFilters($guest, $search, $status) || !$this->matchesBatch($batch, $batchId)) {
                    continue;
                }

                $inScope = $scopeGuard->covers($scope, $guest->vmid, $guest->pool);

                $rows[] = [
                    'host' => $host,
                    'guest' => $guest,
                    'batch' => $batch,
                    'inScope' => $inScope,
                    'canOperate' => $canOperate,
                    // Asked per row rather than once, because the answer differs by action: a
                    // machine may be startable and not stoppable on a host that allows only one.
                    'canStart' => $inScope && $scopeGuard->allows($scope, ProxmoxAction::Start, $guest->vmid, $guest->pool),
                    'canStop' => $inScope && $scopeGuard->allows($scope, ProxmoxAction::Stop, $guest->vmid, $guest->pool),
                    'live' => $liveOperations[$hostKey][$guest->vmid] ?? null,
                ];
            }
        }

        $total = \count($rows);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $rows = \array_slice($rows, (min($page, $pageCount) - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return $this->render('infrastructure/guests.html.twig', [
            'activeNav' => 'guests',
            'rows' => $rows,
            'hosts' => $declared,
            'batches' => $batches->findOrdered(true),
            'total' => $total,
            'totalCount' => $totalCount,
            'failures' => $failures,
            'filters' => ['q' => $search, 'host' => $hostId, 'status' => $status, 'batch' => $batchId],
            'page' => min($page, $pageCount),
            'pageCount' => $pageCount,
            // One query for the whole list, like the operations above: the address a machine holds
            // is the one thing on this screen the hypervisor does not know - it lives in the
            // address registry, keyed by VMID.
            'addresses' => $allocations->findAddressesForVmids(array_map(
                static fn (array $row): int => $row['guest']->vmid,
                $rows,
            )),
        ]);
    }

    /**
     * One of the four power actions. A POST answering JSON: the row updates in place, and the
     * Stimulus poller takes over from the operation id this returns.
     */
    #[Route(
        path: '/infrastructure/hosts/{id}/guests/{node}/{vmid}/{action}',
        name: 'app_infrastructure_guests_power',
        requirements: ['id' => '\d+', 'vmid' => '\d+', 'action' => 'start|shutdown|stop|reboot'],
        methods: ['POST'],
    )]
    public function power(
        Request $request,
        ProxmoxHostRepository $repository,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        GuestPowerRunner $runner,
        int $id,
        string $node,
        int $vmid,
        string $action,
    ): JsonResponse {
        $this->assertValidInfrastructureToken($request);

        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::OPERATE, $host);

        $proxmoxAction = ProxmoxAction::tryFrom($action);

        if (null === $proxmoxAction || !$proxmoxAction->isPowerAction()) {
            throw $this->createNotFoundException();
        }

        try {
            // Re-read rather than trusting the row the browser is showing: the pool and the
            // template flag decide whether this is allowed at all, and both come from Proxmox.
            $guest = $this->findGuest($clientFactory, $inventory, $host, $node, $vmid);
            $operation = $runner->run($host, $guest, $proxmoxAction, $this->currentUser());
        } catch (ProxmoxUnavailableException $exception) {
            return $this->json([
                'ok' => false,
                // Refusals travel as translation keys and Proxmox's own errors as sentences; both
                // come back as text the row can show, and trans() leaves an unknown key untouched.
                'message' => $this->translator->trans($exception->getMessage()),
            ]);
        }

        return $this->json([
            'ok' => true,
            'operationId' => $operation->getId(),
            'statusUrl' => $this->generateUrl('app_infrastructure_operation_status', ['id' => $operation->getId()]),
        ]);
    }

    /** @throws ProxmoxUnavailableException when the machine is not on this host any more */
    private function findGuest(
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        ProxmoxHost $host,
        string $node,
        int $vmid,
    ): ProxmoxGuest {
        foreach ($inventory->guests($clientFactory->operate($host)) as $guest) {
            if ($guest->vmid === $vmid && $guest->node === $node) {
                return $guest;
            }
        }

        throw new ProxmoxUnavailableException('proxmoxGuestGoneMessage');
    }

    private function matchesFilters(ProxmoxGuest $guest, string $search, string $status): bool
    {
        if ('' !== $status && $guest->status !== $status) {
            return false;
        }

        if ('' === $search) {
            return true;
        }

        return str_contains(mb_strtolower($guest->name), mb_strtolower($search))
            || str_contains((string) $guest->vmid, $search);
    }

    /** @param array{id: int, label: string}|null $batch */
    private function matchesBatch(?array $batch, ?int $batchId): bool
    {
        return null === $batchId || (null !== $batch && $batch['id'] === $batchId);
    }
}

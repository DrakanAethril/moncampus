<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Entity\ProxmoxHost;
use App\Enum\ProxmoxAction;
use App\Repository\IpAllocationRepository;
use App\Repository\ProxmoxHostRepository;
use App\Repository\ProxmoxOperationRepository;
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
 * The machines of one host: the list, and the four power actions.
 *
 * Unlike every other list screen in this application, this one **reads the hypervisor as it
 * renders**. That is not an oversight of the "never probe at display" rule, it is the other side of
 * it: the rule is about *hosts*, whose state is a badge on a page listing several of them, where
 * the slowest would hold up the rest. Here there is exactly one host, the screen is *about* it, and
 * showing a stale machine list would be worse than showing an error - somebody is about to press
 * "stop" on one of these rows.
 *
 * Nothing about a machine is stored. A guest created by hand in Proxmox appears here with nothing
 * to synchronise; one destroyed there simply stops being listed.
 *
 * There is no delete action on any row, in any state. That is settled, and the machines list is
 * deliberately not where it gets re-explained line by line - the host form says it once, at the
 * moment the question arises.
 */
#[IsGranted('ROLE_ADMIN')]
class GuestController extends AbstractController
{
    use InfrastructureTrait;

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route(path: '/infrastructure/hosts/{id}/guests', name: 'app_infrastructure_guests', requirements: ['id' => '\d+'])]
    public function index(
        Request $request,
        ProxmoxHostRepository $repository,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        ProxmoxScopeGuard $scopeGuard,
        ProxmoxOperationRepository $operations,
        IpAllocationRepository $allocations,
        int $id,
    ): Response {
        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::VIEW, $host);

        // Every filter through QueryValue: a filter bar whose "Tous" option carries value="" sends
        // `?node=` as a matter of course, and InputBag::getInt() answers 400 to exactly that.
        $search = QueryValue::trimmed($request, 'q');
        $node = QueryValue::trimmed($request, 'node');
        $status = QueryValue::trimmed($request, 'status');
        $type = QueryValue::trimmed($request, 'type');
        $inScopeOnly = QueryValue::bool($request, 'scoped');

        $scope = ProxmoxScope::fromHost($host);
        $failure = null;
        $guests = [];
        $nodes = [];

        try {
            $client = $clientFactory->operate($host);
            $nodes = $inventory->nodes($client);
            $guests = $inventory->guests($client);
        } catch (ProxmoxUnavailableException $exception) {
            $failure = $exception->getMessage();
        }

        $rows = [];
        foreach ($guests as $guest) {
            $inScope = $scopeGuard->covers($scope, $guest->vmid, $guest->pool);

            if (!$this->matchesFilters($guest, $search, $node, $status, $type) || ($inScopeOnly && !$inScope)) {
                continue;
            }

            $rows[] = [
                'guest' => $guest,
                'inScope' => $inScope,
                // Asked per row rather than once, because the answer differs by action: a machine
                // may be startable and not stoppable on a host that allows only one of the two.
                'canStart' => $inScope && $scopeGuard->allows($scope, ProxmoxAction::Start, $guest->vmid, $guest->pool),
                'canStop' => $inScope && $scopeGuard->allows($scope, ProxmoxAction::Stop, $guest->vmid, $guest->pool),
            ];
        }

        return $this->render('infrastructure/guests.html.twig', [
            'activeNav' => 'guests',
            'host' => $host,
            'rows' => $rows,
            'nodes' => $nodes,
            'totalCount' => \count($guests),
            'failure' => $failure,
            'filters' => ['q' => $search, 'node' => $node, 'status' => $status, 'type' => $type, 'scoped' => $inScopeOnly],
            // One query for the whole page rather than one per row - the machines list is the only
            // screen where an operation in flight has to show up next to its machine.
            'liveOperations' => $operations->findUnsettledByVmid($host),
            // One query for the whole list, like the operations above: the address a machine holds
            // is the one thing on this screen the hypervisor does not know - it lives in the
            // address registry, keyed by VMID.
            'addresses' => $allocations->findAddressesForVmids(array_map(
                static fn (array $row): int => $row['guest']->vmid,
                $rows,
            )),
            'canOperate' => $this->isGranted(ProxmoxHostVoter::OPERATE, $host),
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

    private function matchesFilters(ProxmoxGuest $guest, string $search, string $node, string $status, string $type): bool
    {
        if ('' !== $node && $guest->node !== $node) {
            return false;
        }

        if ('' !== $type && $guest->type !== $type) {
            return false;
        }

        // "template" is a state as far as the filter bar is concerned, even though Proxmox carries
        // it as a flag beside the status - which is how an administrator thinks of it.
        if ('' !== $status) {
            $matches = 'template' === $status ? $guest->template : (!$guest->template && $guest->status === $status);

            if (!$matches) {
                return false;
            }
        }

        if ('' === $search) {
            return true;
        }

        return str_contains(mb_strtolower($guest->name), mb_strtolower($search))
            || str_contains((string) $guest->vmid, $search);
    }
}

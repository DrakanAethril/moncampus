<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Attribute\RequiresFeature;
use App\Entity\ProxmoxHost;
use App\Enum\Feature;
use App\Repository\ProxmoxHostRepository;
use App\Security\Voter\ProxmoxHostVoter;
use App\Service\Console\ConsoleAddressUnknownException;
use App\Service\Console\ConsoleLimitReachedException;
use App\Service\Console\ConsoleScreen;
use App\Service\Console\ConsoleSessionOpener;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxGuest;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxScope;
use App\Service\Proxmox\ProxmoxScopeGuard;
use App\Service\Proxmox\ProxmoxUnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The administrator's door onto a machine console.
 *
 * A controller of its own rather than another action on GuestController, for the reason the area
 * already applies elsewhere: « Console » is a *navigation*, the four power actions are *orders*,
 * and the machines list is long enough.
 *
 * This is the second of the console's two doors and the only one an administrator uses. It is
 * guarded by access_control on ^/infrastructure, and it never touches
 * App\Security\Voter\GuestConsoleVoter - which answers a question about holding an account and must
 * keep answering only that. The perimeter here is the managed one, judged by ProxmoxScopeGuard, and
 * a template has no console for the same reason it has no power action.
 *
 * The screen it renders and the exchange route it hands over to are App\Controller\ConsoleController's:
 * one terminal, two ways in.
 */
#[IsGranted('ROLE_ADMIN')]
#[RequiresFeature(Feature::GuestConsole)]
class GuestConsoleController extends AbstractController
{
    use InfrastructureTrait;

    #[Route(
        path: '/infrastructure/hosts/{id}/guests/{node}/{vmid}/console',
        name: 'app_infrastructure_guest_console',
        requirements: ['id' => '\d+', 'vmid' => '\d+'],
        methods: ['GET'],
    )]
    public function index(
        ProxmoxHostRepository $repository,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        ProxmoxScopeGuard $scopeGuard,
        ConsoleSessionOpener $opener,
        ConsoleScreen $screen,
        int $id,
        string $node,
        int $vmid,
    ): Response {
        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::VIEW, $host);

        $guest = $this->findGuest($clientFactory, $inventory, $host, $node, $vmid);

        // Read from the hypervisor rather than trusted from the row that was clicked: whether this
        // is a template and which pool it sits in is what the perimeter is judged on, and only
        // Proxmox knows a machine was moved out of it by hand. A host that is down leaves $guest
        // null, and the console opens anyway - it does not go through Proxmox.
        if (null !== $guest && ($guest->template || !$scopeGuard->covers(ProxmoxScope::fromHost($host), $guest->vmid, $guest->pool))) {
            throw $this->createNotFoundException();
        }

        try {
            $session = $opener->openForMachine($host, $node, $vmid, $this->currentUser());
        } catch (ConsoleLimitReachedException|ConsoleAddressUnknownException $exception) {
            return $this->render('console/refused.html.twig', $screen->refusal($exception) + ['activeNav' => 'guests', 'host' => $host]);
        }

        if (null !== $guest) {
            $session->setGuestName($guest->name);
        }

        return $this->render('console/index.html.twig', $screen->forAdmin($session, $host, $guest));
    }

    private function findGuest(
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        ProxmoxHost $host,
        string $node,
        int $vmid,
    ): ?ProxmoxGuest {
        try {
            foreach ($inventory->guests($clientFactory->operate($host)) as $guest) {
                if ($guest->vmid === $vmid && $guest->node === $node) {
                    return $guest;
                }
            }
        } catch (ProxmoxUnavailableException) {
            return null;
        }

        return null;
    }
}

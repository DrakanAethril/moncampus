<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Repository\IpRangeRepository;
use App\Repository\ProxmoxHostRepository;
use App\Repository\ProxmoxOperationRepository;
use App\Service\Crypto\SecretBoxProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * /infrastructure - the entry point of the Proxmox console, and the page an administrator
 * bookmarks, since nothing in the application's menus leads here.
 *
 * It therefore has to be worth opening on its own: the state of every declared host, the counters
 * that decide whether there is anything to do, and a door into each area. Everything it shows about
 * a host is the *last known* check, timestamped - see App\Service\Proxmox\ProxmoxHostChecker for
 * why the hub never probes anything as it renders.
 */
#[IsGranted('ROLE_ADMIN')]
class HubController extends AbstractController
{
    use InfrastructureTrait;

    #[Route(path: '/infrastructure', name: 'app_infrastructure')]
    public function index(
        ProxmoxHostRepository $hostRepository,
        ProxmoxOperationRepository $operationRepository,
        IpRangeRepository $rangeRepository,
        SecretBoxProvider $secretBoxProvider,
    ): Response {
        $hosts = $hostRepository->findOrdered();

        $reachable = 0;
        $guests = 0;
        $running = 0;
        $unreachable = [];

        foreach ($hosts as $host) {
            if (true === $host->getLastCheckOk()) {
                ++$reachable;
                $guests += $host->getLastGuestCount() ?? 0;
                $running += $host->getLastRunningCount() ?? 0;
                continue;
            }

            // A host that has never been checked is not "down" - it is unknown, and the hub says
            // so rather than counting it as a failure an administrator has to go and disprove.
            if (false === $host->getLastCheckOk()) {
                $unreachable[] = $host;
            }
        }

        return $this->render('infrastructure/index.html.twig', [
            'activeNav' => 'overview',
            'hosts' => $hosts,
            'hostCount' => \count($hosts),
            'reachableCount' => $reachable,
            'unreachableHosts' => $unreachable,
            'guestCount' => $guests,
            'runningCount' => $running,
            'operationCount' => $operationRepository->countAll(),
            'rangeCount' => $rangeRepository->countActive(),
            'encryptionAvailable' => $secretBoxProvider->isAvailable(),
            'encryptionFailure' => $secretBoxProvider->unavailableReason(),
        ]);
    }
}

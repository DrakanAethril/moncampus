<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Entity\ProxmoxHost;
use App\Enum\ProxmoxAction;
use App\Enum\ProxmoxOperationStatus;
use App\Repository\ProxmoxHostRepository;
use App\Repository\ProxmoxOperationRepository;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxOperationTracker;
use App\Service\Proxmox\ProxmoxUnavailableException;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything MonCampus asked a hypervisor to do, and what came back - plus the endpoint the
 * Stimulus poller calls while a task is still running.
 *
 * Polling rather than a queue, and the reason is in this repository rather than in fashion:
 * Messenger here has no worker at all, so routing a long operation to `async` would mean never
 * processing it. Proxmox hands back a UPID and expects to be asked; the screen asks, every two
 * seconds, for at most five minutes.
 */
#[IsGranted('ROLE_ADMIN')]
class OperationController extends AbstractController
{
    use InfrastructureTrait;

    private const int PAGE_SIZE = 50;

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route(path: '/infrastructure/operations', name: 'app_infrastructure_operations')]
    public function index(
        Request $request,
        ProxmoxOperationRepository $repository,
        ProxmoxHostRepository $hosts,
        ProxmoxOperationTracker $tracker,
    ): Response {
        // Rows nobody is watching any more are closed as `unknown` before the list is drawn:
        // an operation left "running" for ever would otherwise read as still going, months later.
        $tracker->settleStale();

        $page = max(1, QueryValue::int($request, 'page', 1));
        $search = QueryValue::trimmed($request, 'q');
        $host = $this->hostFilter($request, $hosts);
        $action = ProxmoxAction::tryFrom(QueryValue::trimmed($request, 'action'));
        $status = ProxmoxOperationStatus::tryFrom(QueryValue::trimmed($request, 'status'));

        $total = $repository->countFiltered($host, $action, $status, $search);

        return $this->render('infrastructure/operations.html.twig', [
            'activeNav' => 'operations',
            'operations' => $repository->findPage(($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, $host, $action, $status, $search),
            'hosts' => $hosts->findOrdered(true),
            'actions' => ProxmoxAction::cases(),
            'statuses' => ProxmoxOperationStatus::cases(),
            'filters' => ['q' => $search, 'host' => $host?->getId(), 'action' => $action?->value, 'status' => $status?->value],
            'total' => $total,
            'page' => $page,
            'pageCount' => max(1, (int) ceil($total / self::PAGE_SIZE)),
        ]);
    }

    /**
     * What the poller asks. Each call re-reads the task from the host if the operation is still
     * open, so the answer is the hypervisor's, not a guess.
     */
    #[Route(path: '/infrastructure/operations/{id}/status', name: 'app_infrastructure_operation_status', requirements: ['id' => '\d+'])]
    public function status(
        ProxmoxOperationRepository $repository,
        ProxmoxClientFactory $clientFactory,
        ProxmoxOperationTracker $tracker,
        int $id,
    ): JsonResponse {
        $operation = $repository->find($id) ?? throw $this->createNotFoundException();
        $host = $operation->getHost();

        if (!$operation->getStatus()->isSettled() && null !== $host) {
            try {
                // By action, not by convenience: a creation task belongs to the provisioning
                // account, and Proxmox charges Sys.Audit for reading another account's task.
                $tracker->resolve($operation, $clientFactory->forAction($host, $operation->getAction()));
            } catch (ProxmoxUnavailableException) {
                // Left as it is: the tracker decides when an unreachable host turns into `unknown`,
                // and it is a matter of elapsed time, not of one failed poll.
            }
        }

        return $this->json([
            'status' => $operation->getStatus()->value,
            'settled' => $operation->getStatus()->isSettled(),
            'label' => $this->translator->trans($operation->getStatus()->labelKey()),
            'badge' => $operation->getStatus()->badgeModifier(),
            'message' => $operation->getMessage(),
            'durationSeconds' => $operation->durationSeconds(),
        ]);
    }

    /**
     * The journal as a CSV, for the filters currently applied. Streamed rather than built in
     * memory: the log is the one table here that grows without bound.
     */
    #[Route(path: '/infrastructure/operations/export', name: 'app_infrastructure_operations_export')]
    public function export(Request $request, ProxmoxOperationRepository $repository, ProxmoxHostRepository $hosts): StreamedResponse
    {
        $host = $this->hostFilter($request, $hosts);
        $action = ProxmoxAction::tryFrom(QueryValue::trimmed($request, 'action'));
        $status = ProxmoxOperationStatus::tryFrom(QueryValue::trimmed($request, 'status'));
        $search = QueryValue::trimmed($request, 'q');

        $response = new StreamedResponse(function () use ($repository, $host, $action, $status, $search): void {
            $handle = fopen('php://output', 'w');

            if (false === $handle) {
                return;
            }

            // A BOM, so Excel opens the accented columns as UTF-8 instead of guessing.
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, ['Quand', 'Action', 'Hôte', 'Nœud', 'VMID', 'Machine', 'Auteur', 'Résultat', 'Détail', 'UPID'], ';', '"', '\\');

            $offset = 0;
            do {
                $rows = $repository->findPage($offset, 200, $host, $action, $status, $search);

                foreach ($rows as $operation) {
                    fputcsv($handle, [
                        $operation->getRequestedAt()->format('d/m/Y H:i:s'),
                        $this->translator->trans($operation->getAction()->labelKey()),
                        $operation->getHostLabel(),
                        $operation->getNode() ?? '',
                        $operation->getVmid() ?? '',
                        $operation->getGuestName() ?? '',
                        $operation->getRequestedByLabel(),
                        $this->translator->trans($operation->getStatus()->labelKey()),
                        $operation->getMessage() ?? '',
                        $operation->getUpid() ?? '',
                    ], ';', '"', '\\');
                }

                $offset += 200;
            } while ([] !== $rows);

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="journal-proxmox.csv"');

        return $response;
    }

    private function hostFilter(Request $request, ProxmoxHostRepository $hosts): ?ProxmoxHost
    {
        $hostId = QueryValue::nullableInt($request, 'host');

        return null !== $hostId ? $hosts->find($hostId) : null;
    }
}

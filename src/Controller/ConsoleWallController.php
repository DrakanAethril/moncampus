<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Entity\VmBatch;
use App\Enum\Feature;
use App\Repository\GuestAccountRepository;
use App\Repository\VmBatchRepository;
use App\Service\Console\ConsoleWallReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The console wall: one tile per machine of a batch, the last lines of each screen.
 *
 * « Où en sont-ils ? » gets asked twenty times a session and has, today, no answer at all. This is
 * that answer, and it is **read-only**: one looks at the class, one does not type into it. A click
 * opens the console of that machine, which is where typing happens.
 *
 * **One tile per request.** The browser asks for four at a time on a fifteen-second cycle, so
 * twenty-four tiles take about two seconds of wall, each request is short, and the workers keep
 * turning. One request drawing the whole wall would hold a worker for the sum of twenty-four SSH
 * handshakes - the wall is the most expensive thing in this feature to refresh, which is why it is
 * built last and why it is built this way.
 *
 * Access mirrors the console's two doors without inventing a third: a teacher of the batch's
 * formation, or an administrator. There is no per-machine account check here, because a wall is
 * about a class and not about a machine - and there is nothing on it that typing into a console
 * would not already show.
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::GuestConsole)]
class ConsoleWallController extends AbstractController
{
    #[Route(path: '/console/batch/{id}', name: 'app_console_wall', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(VmBatchRepository $batches, int $id): Response
    {
        $batch = $this->readableBatch($batches, $id);

        $machines = [];

        foreach ($batch->getItems() as $item) {
            if (null !== $item->getVmid()) {
                $machines[] = $item;
            }
        }

        return $this->render('console/wall.html.twig', [
            'batch' => $batch,
            'machines' => $machines,
        ]);
    }

    /** One tile. Short, independent, and allowed to fail without taking the others with it. */
    #[Route(path: '/console/batch/{id}/tile/{vmid}', name: 'app_console_wall_tile', requirements: ['id' => '\d+', 'vmid' => '\d+'], methods: ['GET'])]
    public function tile(VmBatchRepository $batches, ConsoleWallReader $reader, int $id, int $vmid): JsonResponse
    {
        $batch = $this->readableBatch($batches, $id);

        foreach ($batch->getItems() as $item) {
            if ($item->getVmid() === $vmid) {
                return $this->json($reader->read($item));
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * A click on a tile: the console of that machine, by whichever of the two doors applies.
     *
     * A redirect rather than a link built in the template, because the door depends on the reader:
     * a teacher goes through the account they hold on the machine, an administrator through
     * /infrastructure. The template would have to know both, and would get it wrong for whoever it
     * was not written for.
     */
    #[Route(path: '/console/batch/{id}/open/{vmid}', name: 'app_console_wall_open', requirements: ['id' => '\\d+', 'vmid' => '\\d+'], methods: ['GET'])]
    public function open(VmBatchRepository $batches, GuestAccountRepository $accounts, int $id, int $vmid): Response
    {
        $batch = $this->readableBatch($batches, $id);
        $host = $batch->getHost();
        $user = $this->getUser();

        if ($user instanceof User && null !== $host) {
            foreach ($accounts->findForMachine($host, $batch->getNode(), $vmid) as $account) {
                if ($account->getUser()?->getId() === $user->getId()) {
                    return $this->redirectToRoute('app_console', ['id' => $account->getId()]);
                }
            }
        }

        if ($this->isGranted('ROLE_ADMIN') && null !== $host) {
            return $this->redirectToRoute('app_infrastructure_guest_console', [
                'id' => $host->getId(),
                'node' => $batch->getNode(),
                'vmid' => $vmid,
            ]);
        }

        // A teacher of the class who holds no account on this particular machine: the wall shows it,
        // the console does not open on it. That is the perimeter doing its job, not a broken link.
        throw $this->createAccessDeniedException();
    }

    /**
     * The batch, or a refusal - a teacher of its formation, or an administrator.
     *
     * Read off Program::$teachers directly rather than through StructureAccessChecker, for the same
     * reason App\Security\Voter\GuestConsoleVoter does: that helper is staff-bypassed by design, and
     * importing the bypass would quietly turn « I teach this class » into « I outrank it ».
     */
    private function readableBatch(VmBatchRepository $batches, int $id): VmBatch
    {
        $batch = $batches->find($id) ?? throw $this->createNotFoundException();

        if ($this->isGranted('ROLE_ADMIN')) {
            return $batch;
        }

        $program = $batch->getProgram();
        $user = $this->getUser();

        if (!$user instanceof User || null === $program || !$program->getTeachers()->contains($user)) {
            throw $this->createAccessDeniedException();
        }

        return $batch;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Repository\ConsoleSessionRepository;
use App\Repository\UserRepository;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The journal of console sessions, and one transcript.
 *
 * **The lot that is not postponed.** A console opens on an account with passwordless `sudo`, and a
 * root-capable door with no trace has no business staying in production for the length of a
 * comfort feature. It sits under /infrastructure beside the operations journal, and opening a
 * console also shows up *there* as a ProxmoxOperation, at the same place as a start - the same
 * question, asked of the same list.
 *
 * What a transcript holds is **the panel, never the keystrokes**. A password typed at a `sudo` or
 * `passwd` prompt does not appear on the screen - the terminal is hiding it - so it is exactly what
 * a recording of the screen does not capture. That is a property of what is recorded, not a promise
 * made by this screen: see App\Service\Console\ConsoleTranscript.
 *
 * Retention is ninety days, applied by `app:purge-platform-activity`, and the screen says so.
 */
#[IsGranted('ROLE_ADMIN')]
class ConsoleSessionController extends AbstractController
{
    /** How far back the filter bar can look, and its default. */
    private const int DEFAULT_DAYS = 7;

    #[Route(path: '/infrastructure/console-sessions', name: 'app_infrastructure_console_sessions', methods: ['GET'])]
    public function index(Request $request, ConsoleSessionRepository $sessions, UserRepository $users): Response
    {
        // Every filter through QueryValue: a filter bar whose « Toutes » option carries value=""
        // sends `?vmid=` as a matter of course, and InputBag::getInt() answers 400 to exactly that.
        $vmid = QueryValue::nullableInt($request, 'vmid');
        $userId = QueryValue::nullableInt($request, 'user');
        $days = QueryValue::int($request, 'days', self::DEFAULT_DAYS);
        $days = max(1, min($days, 90));

        $rows = $sessions->findForJournal($vmid, $userId, new \DateTimeImmutable(\sprintf('-%d days', $days)));

        return $this->render('console/journal.html.twig', [
            'activeNav' => 'console_sessions',
            'sessions' => $rows,
            'filters' => ['vmid' => $vmid, 'user' => $userId, 'days' => $days],
            // The machines and the people that actually appear, rather than every machine and every
            // account: a filter offering options that can only ever return nothing is a filter that
            // teaches people not to use it.
            'machines' => $this->machinesOf($sessions),
            'people' => $users->findBy(['id' => $this->peopleIdsOf($sessions)], ['lastname' => 'ASC']),
            'retentionDays' => 90,
        ]);
    }

    #[Route(path: '/infrastructure/console-sessions/{id}', name: 'app_infrastructure_console_session', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ConsoleSessionRepository $sessions, int $id): Response
    {
        $session = $sessions->find($id) ?? throw $this->createNotFoundException();

        return $this->render('console/transcript.html.twig', [
            'activeNav' => 'console_sessions',
            'session' => $session,
        ]);
    }

    /**
     * The machines that appear in the journal at all, as (vmid => name).
     *
     * @return array<int, string>
     */
    private function machinesOf(ConsoleSessionRepository $sessions): array
    {
        $machines = [];

        foreach ($sessions->findForJournal(null, null, new \DateTimeImmutable('-90 days'), 1000) as $session) {
            $machines[$session->getVmid()] = $session->getGuestName() ?? \sprintf('VM %d', $session->getVmid());
        }

        asort($machines);

        return $machines;
    }

    /** @return list<int> */
    private function peopleIdsOf(ConsoleSessionRepository $sessions): array
    {
        $ids = [];

        foreach ($sessions->findForJournal(null, null, new \DateTimeImmutable('-90 days'), 1000) as $session) {
            $id = $session->getOpenedBy()?->getId();

            if (null !== $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}

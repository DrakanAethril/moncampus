<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use App\Entity\InternshipReminderBatch;
use App\Entity\InternshipReminderBatchItem;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\InternshipReminderBatchItemRepository;
use App\Service\AlternanceReminderBoard;
use App\Service\AlternanceReminderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Freezes a reminder batch (R1): the alternances its target waits on in the chosen formations, each
 * to send or set aside with its reason. Nothing is sent here - the scheduled pass does that
 * (ReminderBatchSender).
 *
 * The count the form shows and the count the launch freezes come from the same preview(); the
 * launch refuses a count that moved in between, so that the person confirms what will really go.
 */
final readonly class ReminderBatchLauncher
{
    private const string LOCK = 'ufa-reminder-batch-launch';

    public function __construct(
        private AlternanceReminderBoard $board,
        private ReminderBatchPlanner $planner,
        private InternshipReminderBatchItemRepository $itemRepository,
        private AlternanceReminderService $reminderService,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    /**
     * What a batch on this target would take today, formation by formation.
     *
     * @param list<Program> $programs
     */
    public function preview(ReminderBatchTarget $target, array $programs): ReminderBatchPlan
    {
        return $this->planOf($target, $this->board->waiting($programs, $target))['plan'];
    }

    /**
     * Every target somebody waits on in these formations, busiest first within the livret's order,
     * with how many wait - the form's dropdown.
     *
     * @param list<Program> $programs
     *
     * @return list<array{target: ReminderBatchTarget, waiting: int}>
     */
    public function targets(array $programs): array
    {
        $byKey = [];
        foreach ($this->board->waiting($programs) as $entry) {
            $key = $entry->target->key();
            $byKey[$key] ??= ['target' => $entry->target, 'waiting' => 0];
            ++$byKey[$key]['waiting'];
        }

        $targets = array_values($byKey);
        usort($targets, static fn (array $a, array $b): int => strcmp($a['target']->sortKey(), $b['target']->sortKey()));

        return $targets;
    }

    /**
     * Null when there is nothing to send, or when the number to send is no longer $expectedToSend:
     * the form then shows the new count and waits for a new click.
     *
     * @param list<Program> $programs
     */
    public function launch(ReminderBatchTarget $target, bool $allPrograms, array $programs, User $launchedBy, int $expectedToSend): ?InternshipReminderBatch
    {
        $schoolYear = $this->board->schoolYear();
        if (null === $schoolYear || [] === $programs) {
            return null;
        }

        // Two colleagues launching the same step at the same moment would each find the other's
        // people still free (R3): the planning and the freeze happen under one lock.
        $lock = $this->lockFactory->createLock(self::LOCK, 30);
        $lock->acquire(true);

        try {
            ['plan' => $plan, 'waiting' => $waitingByLinkId] = $this->planOf($target, $this->board->waiting($programs, $target));
            if (0 === $plan->toSendCount() || $plan->toSendCount() !== $expectedToSend) {
                return null;
            }

            $programsById = [];
            foreach ($programs as $program) {
                $programsById[(int) $program->getId()] = $program;
            }

            $batch = new InternshipReminderBatch($target, $allPrograms, $programs, $schoolYear, $launchedBy, $this->clock->now());

            $this->entityManager->wrapInTransaction(function () use ($batch, $plan, $waitingByLinkId, $programsById): void {
                $this->entityManager->persist($batch);
                foreach ($plan->lines as $line) {
                    $entry = $waitingByLinkId[$line['tutorLinkId']];
                    $this->entityManager->persist(new InternshipReminderBatchItem($batch, $entry->tutorLink, $programsById[$line['programId']], $entry->period, $line['exclusion']));
                }
            });

            return $batch;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<ReminderWaiting> $waiting
     *
     * @return array{plan: ReminderBatchPlan, waiting: array<int, ReminderWaiting>}
     */
    private function planOf(ReminderBatchTarget $target, array $waiting): array
    {
        $step = $target->step->reminderStep();
        $queued = $this->itemRepository->findQueuedKeys();

        $candidates = [];
        $queuedLinkIds = [];
        $byLinkId = [];
        foreach ($waiting as $entry) {
            $linkId = (int) $entry->tutorLink->getId();
            $byLinkId[$linkId] = $entry;
            $candidates[] = [
                'tutorLinkId' => $linkId,
                'programId' => (int) $entry->program->getId(),
                'hasAddress' => null !== $this->reminderService->recipients($entry->tutorLink, $step)['to'],
            ];
            if (isset($queued[InternshipReminderBatchItemRepository::queuedKey($linkId, $target)])) {
                $queuedLinkIds[$linkId] = true;
            }
        }

        return ['plan' => $this->planner->plan($candidates, $queuedLinkIds), 'waiting' => $byLinkId];
    }
}

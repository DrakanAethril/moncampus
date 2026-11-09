<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use App\Entity\InternshipReminderBatch;
use App\Entity\InternshipReminderBatchItem;
use App\Enum\ReminderBatchItemStatus;
use App\Enum\ReminderBatchStatus;
use App\Repository\InternshipReminderBatchItemRepository;
use App\Repository\InternshipReminderBatchRepository;
use App\Service\AlternancePeriodStatusResolver;
use App\Service\AlternanceReminderService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * One pass of the reminder batches - what `app:ufa:reminder-batches:send` runs every minute.
 *
 * The running batches are served oldest first, up to the platform's ceiling for the minute
 * (ReminderBatchPace), within the sending hours. Each line is read again just before its mail (R2),
 * then written « En envoi » **before** SES is called, then « Envoyée » with SES's identifier. A line
 * still « En envoi » when the next pass starts belongs to a pass that died in between - the command
 * is locked, so no other pass can be holding it - and becomes « Incertaine »: nothing ever sends it
 * again on its own (R6), a duplicate at a tutor's costs more than a line nobody is sure about.
 *
 * A passing SES error (throttling, no answer) puts the line back for the next pass and stops this
 * one: insisting would only worsen the limit. A refused mail fails its line at once.
 */
final readonly class ReminderBatchSender
{
    public function __construct(
        private InternshipReminderBatchRepository $batchRepository,
        private InternshipReminderBatchItemRepository $itemRepository,
        private AlternanceReminderService $reminderService,
        private AlternancePeriodStatusResolver $statusResolver,
        private ReminderBatchPace $pace,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{sent: int, settled: int, failed: int, uncertain: int, finished: int, stopped: string|null}
     */
    public function runPass(): array
    {
        $report = ['sent' => 0, 'settled' => 0, 'failed' => 0, 'uncertain' => 0, 'finished' => 0, 'stopped' => null];
        $now = $this->clock->now();

        foreach ($this->itemRepository->findSending() as $item) {
            $item->settleWithoutMail(ReminderBatchItemStatus::Uncertain, $now);
            ++$report['uncertain'];
        }
        $this->entityManager->flush();

        $batches = $this->batchRepository->findRunningOldestFirst();

        if (!$this->pace->isOpen($now)) {
            $report['stopped'] = 'closed';
        } else {
            $budget = $this->pace->perMinute;
            foreach ($batches as $batch) {
                $budget = $this->serve($batch, $budget, $report);
                if ($budget <= 0 || null !== $report['stopped']) {
                    break;
                }
            }
        }

        foreach ($batches as $batch) {
            if (ReminderBatchStatus::Running === $batch->getStatus() && 0 === $this->itemRepository->countPending($batch)) {
                $batch->finish($this->clock->now());
                ++$report['finished'];
            }
        }
        $this->entityManager->flush();

        return $report;
    }

    /**
     * @param array{sent: int, settled: int, failed: int, uncertain: int, finished: int, stopped: string|null} $report
     */
    private function serve(InternshipReminderBatch $batch, int $budget, array &$report): int
    {
        while ($budget > 0) {
            // Paused or cancelled from the screen while this pass was busy with an older batch.
            $this->entityManager->refresh($batch);
            if (ReminderBatchStatus::Running !== $batch->getStatus()) {
                return $budget;
            }

            $items = $this->itemRepository->findPending($batch, $budget);
            if ([] === $items) {
                return $budget;
            }

            foreach ($items as $item) {
                $verdict = $this->recheck($batch, $item);
                if (null !== $verdict) {
                    $item->settleWithoutMail($verdict, $this->clock->now());
                    $this->entityManager->flush();
                    ++$report['settled'];
                    continue;
                }

                $item->claim($this->clock->now());
                $this->entityManager->flush();

                if (!$this->deliver($batch, $item, $report)) {
                    return 0;
                }
                --$budget;
                if ($budget <= 0) {
                    return 0;
                }
            }
        }

        return $budget;
    }

    /**
     * False when the pass must stop here.
     *
     * @param array{sent: int, settled: int, failed: int, uncertain: int, finished: int, stopped: string|null} $report
     */
    private function deliver(InternshipReminderBatch $batch, InternshipReminderBatchItem $item, array &$report): bool
    {
        $tutorLink = $item->getTutorLink();
        $launchedBy = $batch->getCreatedBy();
        if (null === $tutorLink || null === $launchedBy) {
            $item->fail('Ligne incomplète.', null, $this->clock->now());
            $this->entityManager->flush();
            ++$report['failed'];

            return true;
        }

        try {
            $delivery = $this->reminderService->sendForBatch($batch, $tutorLink, $item->getEvaluationPeriod(), $launchedBy);
        } catch (ReminderDeliveryException $exception) {
            $recipient = $this->reminderService->recipients($tutorLink, $batch->getStep()->reminderStep())['to'];
            if ($exception->passing) {
                $item->deferAfterError($exception->getMessage(), $this->clock->now());
                $this->entityManager->flush();
                $report['stopped'] = 'passing_error';
                if (ReminderBatchItemStatus::Failed === $item->getStatus()) {
                    ++$report['failed'];
                }
                $this->logger->warning('Reminder batch pass stopped by a passing SES error.', ['batch' => $batch->getId(), 'item' => $item->getId(), 'error' => $exception->getMessage()]);

                return false;
            }

            $item->fail($exception->getMessage(), $recipient, $this->clock->now());
            $this->entityManager->flush();
            ++$report['failed'];

            return true;
        }

        $item->markSent($delivery->reminder, $delivery->recipientEmail, $delivery->copiedSecondaryTutor, $delivery->providerMessageId, $this->clock->now());
        $this->entityManager->flush();
        ++$report['sent'];

        return true;
    }

    private function recheck(InternshipReminderBatch $batch, InternshipReminderBatchItem $item): ?ReminderBatchItemStatus
    {
        $tutorLink = $item->getTutorLink();
        if (null === $tutorLink || $tutorLink->isTerminated()) {
            return ReminderBatchItemStatus::NotConcerned;
        }

        $step = $batch->getStep();
        if (!$step->isPeriod()) {
            return ReminderBatchRecheck::verdict($step, $this->statusResolver->resolveCurrentStep($tutorLink)->step);
        }

        $period = $item->getEvaluationPeriod();
        if (null === $period || null !== $period->getInactiveDate() || $period->getProgram() !== $tutorLink->getProgram()) {
            return ReminderBatchItemStatus::NotConcerned;
        }

        return ReminderBatchRecheck::verdict($step, $this->statusResolver->resolveStepForPeriod($tutorLink, $period)->step);
    }
}

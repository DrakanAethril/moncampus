<?php

namespace App\Service;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Repository\LessonLogRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\SeanceInstanceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Powers the "semaine type" bulk-apply tool (App\Controller\ProgramTimetableSettingsController's
 * weeklyTemplateForm()/applyWeeklyTemplate()): staff define a Monday-Saturday pattern of draft
 * LessonSessions once (never persisted on its own) and apply it across one or more "périodes"
 * (date ranges) in one shot, optionally clearing whatever already exists in those ranges first.
 *
 * Périodes are restricted to strictly future dates (see validatePeriods()) specifically so the
 * replace step can safely clear any LessonLog ("cahier de texte") or SeanceInstance attached to a
 * session being replaced without a separate "protected session" detection/blocking step - a
 * future-dated période is never expected to have either yet.
 *
 * @phpstan-type Period array{start: \DateTimeImmutable, end: \DateTimeImmutable}
 * @phpstan-type DraftSession array{
 *     dayOfWeek: int,
 *     startHour: \DateTimeImmutable,
 *     endHour: \DateTimeImmutable,
 *     length: string,
 *     title: ?string,
 *     topic: ?\App\Entity\Topic,
 *     teacher: ?\App\Entity\User,
 *     classRoom: ?\App\Entity\Room,
 *     lessonType: ?\App\Entity\LessonType,
 *     options: list<\App\Entity\Option>,
 * }
 */
class WeeklyTemplateApplier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LessonSessionRepository $lessonSessionRepository,
        private readonly LessonLogRepository $lessonLogRepository,
        private readonly SeanceInstanceRepository $seanceInstanceRepository,
    ) {
    }

    /**
     * @param list<Period> $periods
     *
     * @return list<array{index: int, field: string, messageKey: string}>
     */
    public function validatePeriods(array $periods, Program $program): array
    {
        $violations = [];
        $today = new \DateTimeImmutable('today');
        $effectiveStart = $program->getEffectiveStartDate();
        $effectiveEnd = $program->getEffectiveEndDate();

        foreach ($periods as $index => $period) {
            $start = $period['start'];
            $end = $period['end'];

            if (1 !== (int) $start->format('N')) {
                $violations[] = ['index' => $index, 'field' => 'start', 'messageKey' => 'weeklyTemplatePeriodStartNotMondayMessage'];
            }

            if (!\in_array((int) $end->format('N'), [6, 7], true)) {
                $violations[] = ['index' => $index, 'field' => 'end', 'messageKey' => 'weeklyTemplatePeriodEndNotSaturdayOrSundayMessage'];
            }

            if ($end < $start) {
                $violations[] = ['index' => $index, 'field' => 'end', 'messageKey' => 'weeklyTemplatePeriodEndBeforeStartMessage'];
            }

            if ($start <= $today) {
                $violations[] = ['index' => $index, 'field' => 'start', 'messageKey' => 'weeklyTemplatePeriodStartNotInFutureMessage'];
            }

            if ((null !== $effectiveStart && $start < $effectiveStart) || (null !== $effectiveEnd && $end > $effectiveEnd)) {
                $violations[] = ['index' => $index, 'field' => 'start', 'messageKey' => 'weeklyTemplatePeriodOutsideProgramDatesMessage'];
            }
        }

        return $violations;
    }

    /**
     * @param list<DraftSession> $draftSessions
     * @param list<Period>       $periods
     */
    public function apply(Program $program, array $draftSessions, array $periods, bool $replace): int
    {
        return $this->entityManager->wrapInTransaction(function () use ($program, $draftSessions, $periods, $replace): int {
            if ($replace) {
                foreach ($periods as $period) {
                    $this->clearRange($program, $period['start'], $period['end']);
                }
            }

            $created = 0;

            foreach ($periods as $period) {
                $cursor = $period['start'];

                while ($cursor <= $period['end']) {
                    foreach ($draftSessions as $draft) {
                        $day = $cursor->modify(\sprintf('+%d days', $draft['dayOfWeek'] - 1));

                        $lessonSession = (new LessonSession($program))
                            ->setDay($day)
                            ->setStartHour($draft['startHour'])
                            ->setEndHour($draft['endHour'])
                            ->setLength($draft['length'])
                            ->setTitle($draft['title'])
                            ->setTopic($draft['topic'])
                            ->setTeacher($draft['teacher'])
                            ->setClassRoom($draft['classRoom'])
                            ->setLessonType($draft['lessonType']);

                        foreach ($draft['options'] as $option) {
                            $lessonSession->addOption($option);
                        }

                        $this->entityManager->persist($lessonSession);
                        ++$created;
                    }

                    $cursor = $cursor->modify('+7 days');
                }
            }

            $this->entityManager->flush();

            return $created;
        });
    }

    // Removes every LessonSession this Program has between $start/$end (inclusive), cascading any
    // LessonLog (its own $attachments already cascade via orphanRemoval) and unlinking - not
    // deleting - any SeanceInstance, which is a persistent teaching-library record independent of
    // being scheduled (see that entity's own docblock: a null lessonSession is its normal
    // not-yet-scheduled state, not an error).
    private function clearRange(Program $program, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        foreach ($this->lessonSessionRepository->findForProgramBetween($program, $start, $end) as $lessonSession) {
            $lessonLog = $this->lessonLogRepository->findOneBySession($lessonSession);

            if (null !== $lessonLog) {
                $this->entityManager->remove($lessonLog);
            }

            $seanceInstance = $this->seanceInstanceRepository->findOneByLessonSession($lessonSession);
            $seanceInstance?->setLessonSession(null);

            $this->entityManager->remove($lessonSession);
        }
    }
}

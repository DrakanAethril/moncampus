<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptEvent;
use App\Enum\QuizAttemptEventType;
use App\Enum\QuizEventClient;
use App\Repository\QuizAttemptEventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single place the supervision journal is written - web beacon and mobile alike.
 *
 * **The timestamps come from the server, not from the client.** Leaving and coming back are two
 * separate beacons, and the length of an absence is the difference between two `occurred_at` the
 * application wrote itself. A client that lies can therefore only ever accuse itself.
 *
 * A duration declared by the client is read in exactly one case: the departure beacon was lost - a
 * tab killed outright, a phone that never got to send - and then it is bounded by the instants that
 * *are* known on either side (see boundedDurationMs()). Bounded, it can only shorten an absence
 * relative to the truth, never invent one.
 */
class QuizSupervisionJournal
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuizAttemptEventRepository $events,
    ) {
    }

    /**
     * Writes one fact, and closes the absence it ends if it ends one.
     *
     * @param int|null $declaredDurationMs what the client says the absence lasted, only ever read
     *                                     when the departure beacon is missing
     */
    public function record(
        QuizAttempt $attempt,
        QuizAttemptEventType $type,
        ?int $position,
        QuizEventClient $client,
        ?int $declaredDurationMs = null,
        ?\DateTimeImmutable $now = null,
    ): QuizAttemptEvent {
        $now ??= new \DateTimeImmutable();

        $event = new QuizAttemptEvent($attempt, $type, $now, $client);
        $event->setPosition($position);
        $this->entityManager->persist($event);

        $opener = $type->opener();
        if (null !== $opener) {
            $this->closeAbsence($attempt, $opener, $client, $declaredDurationMs, $now);
        }

        $this->entityManager->flush();

        return $event;
    }

    /**
     * Closes the absence this return ends. Two cases, and the second is the one worth writing down:
     * the departure beacon never arrived, so there is nothing to close - the absence is reconstructed
     * from the client's own claim, bounded by the last instant the application knows this attempt
     * was present at.
     */
    private function closeAbsence(
        QuizAttempt $attempt,
        QuizAttemptEventType $opener,
        QuizEventClient $client,
        ?int $declaredDurationMs,
        \DateTimeImmutable $now,
    ): void {
        $open = $this->events->findOpenAbsence($attempt, $opener);
        if (null !== $open) {
            // Read through preciseTimestamp(), which puts the two halves of the instant back
            // together - the seconds column and the millisecond one.
            $open->setDurationMs(max(0, (int) round(((float) $now->format('U.u') - $open->preciseTimestamp()) * 1000)));

            return;
        }

        if (null === $declaredDurationMs || $declaredDurationMs <= 0) {
            return;
        }

        $lastKnown = $this->events->findLastOccurredAt($attempt) ?? $attempt->getStartedAt();
        $duration = self::boundedDurationMs($declaredDurationMs, $lastKnown, $now);
        if (0 === $duration) {
            return;
        }

        $reconstructed = new QuizAttemptEvent($attempt, $opener, $now->modify(\sprintf('-%d milliseconds', $duration)), $client);
        $reconstructed->setDurationMs($duration);
        $this->entityManager->persist($reconstructed);
    }

    /**
     * A client-declared duration, clamped to what the known instants allow: an absence cannot have
     * started before the last moment the application saw this attempt, and cannot be negative.
     *
     * Static and over primitives on purpose - this is the one piece of arithmetic in the journal
     * that decides how much a client is believed, and its test should not have to build an attempt.
     */
    public static function boundedDurationMs(?int $declaredMs, \DateTimeImmutable $lastKnown, \DateTimeImmutable $now): int
    {
        if (null === $declaredMs || $declaredMs <= 0) {
            return 0;
        }

        return max(0, min($declaredMs, self::elapsedMs($lastKnown, $now)));
    }

    private static function elapsedMs(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return max(0, (int) round(((float) $to->format('U.u') - (float) $from->format('U.u')) * 1000));
    }
}

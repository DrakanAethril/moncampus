<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAttempt;
use App\Enum\AttemptStatus;
use App\Enum\QuizSupervisionPolicy;
use App\Repository\QuizAttemptEventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the student is told while composing, and - only under the third policy - the automatic
 * hand-in.
 *
 * The live warning is the most effective of the three treatments by a distance, because it acts
 * *during*, while the decision is still being made: a student who finds out at question 4 that
 * their exits are counted does not start again at question 5. The other two levels only ever
 * document what has already happened.
 *
 * The banner states facts - how many exits, and how long each lasted - and never an accusation.
 * That is what makes it bearable for the student whose screen went to sleep, and dissuasive for the
 * other one.
 *
 * The automatic hand-in goes through App\Service\QuizAttemptConcluder, the same object that closes
 * every other attempt: the copy is handed in as it stands and marked normally. No cancellation, no
 * zero, no "fraud" mention - what was answered counts, the rest is empty.
 */
class QuizSupervisionNotice
{
    public function __construct(
        private readonly QuizAttemptEventRepository $events,
        private readonly QuizAttemptConcluder $concluder,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The absences this attempt has come back from that are long enough to count, in order.
     *
     * @return list<int> durations in milliseconds
     */
    public function countedAbsences(QuizAttempt $attempt): array
    {
        $instance = $attempt->getQuizInstance();

        if (!$instance->isSupervised()) {
            return [];
        }

        return $this->events->findAbsencesAtLeast($attempt, $instance->getSupervisionExitSeconds() * 1000);
    }

    /**
     * Whether the student should see the banner right now.
     *
     * From the first counted exit: a student who has left once and is told so is exactly the person
     * the warning is for, and waiting for a second one would be waiting for the habit to form.
     * « Enregistrer seulement » shows nothing at all - for an assessment invigilated in the room,
     * where the banner would only duplicate the person standing at the back.
     *
     * @param list<int> $absences from countedAbsences()
     */
    public function shouldWarn(QuizAttempt $attempt, array $absences): bool
    {
        return $attempt->getQuizInstance()->getSupervisionPolicy()->warnsStudent() && [] !== $absences;
    }

    /**
     * Hands the copy in when the teacher asked for that and the count has been reached. Returns
     * true when it just did.
     *
     * Called from the beacon endpoint, which is where the count actually moves, and again when a
     * question is served - a beacon may be the last thing a tab ever sends, and the rule must not
     * depend on one more arriving.
     */
    public function autoSubmitIfDue(QuizAttempt $attempt): bool
    {
        $instance = $attempt->getQuizInstance();
        $limit = $instance->getSupervisionSubmitAt();

        if ($attempt->isConcluded()
            || !$instance->isSupervised()
            || QuizSupervisionPolicy::Autosubmit !== $instance->getSupervisionPolicy()
            || null === $limit
        ) {
            return false;
        }

        if (\count($this->countedAbsences($attempt)) < $limit) {
            return false;
        }

        // Termine, not a status of its own: the copy is marked exactly like any other. A separate
        // status would be the "fraud" mention this design refuses to store.
        $this->concluder->conclude($attempt, AttemptStatus::Termine);
        $this->entityManager->flush();

        return true;
    }

    /** Whether this concluded attempt was the one handed in by the rule above. */
    public function wasAutoSubmitted(QuizAttempt $attempt): bool
    {
        $instance = $attempt->getQuizInstance();
        $limit = $instance->getSupervisionSubmitAt();

        return $attempt->isConcluded()
            && $instance->isSupervised()
            && QuizSupervisionPolicy::Autosubmit === $instance->getSupervisionPolicy()
            && null !== $limit
            && \count($this->countedAbsences($attempt)) >= $limit;
    }
}

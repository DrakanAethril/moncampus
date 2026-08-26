<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAttempt;
use App\Enum\QuizAttemptEventType;
use App\Enum\QuizEventClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Which browser session owns a supervised attempt - and, above all, what happens to the other one.
 *
 * An attempt open twice (two tabs, two machines) lets one side read the whole paper while the other
 * answers, and lets somebody else compose in parallel. So there is an owner: a random key written
 * on the attempt and kept in the PHP session.
 *
 * **The last session to open it takes over; it never refuses.** Refusing the second opening is the
 * tempting rule and the wrong one: a browser that crashes, a tab closed by accident, a session that
 * expires - and the student is shut outside in the middle of an exam. Taking over costs nothing to
 * the honest one and makes both simultaneous openings useless: whoever is reading the paper in the
 * second tab loses the hand the moment the first one answers. A `TakenOver` event is written, so
 * the timeline says it happened.
 *
 * The key doubles as what authenticates a beacon: it is per-attempt, random, and known only to the
 * session that holds the attempt - so a beacon from a dispossessed tab is refused without the
 * endpoint having to ask the PHP session anything, which a beacon fired at tab-close cannot rely on.
 */
class QuizAttemptSessionLock
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuizSupervisionJournal $journal,
    ) {
    }

    /**
     * Takes the attempt over for this browser session and returns the new key.
     *
     * Idempotent-looking but never idempotent on purpose: entering the passation again always mints
     * a fresh key, which is exactly what dispossesses whoever held it.
     */
    public function claim(QuizAttempt $attempt, SessionInterface $session, ?int $position = null): string
    {
        $previous = $attempt->getSessionKey();

        $key = bin2hex(random_bytes(24));
        $attempt->setSessionKey($key);
        $session->set(self::sessionName($attempt), $key);
        $this->entityManager->flush();

        // Only when somebody actually lost the hand: the first opening of an attempt dispossesses
        // nobody, and a « repris ailleurs » line on it would be a lie.
        if (null !== $previous) {
            $this->journal->record($attempt, QuizAttemptEventType::TakenOver, $position, QuizEventClient::Web);
        }

        return $key;
    }

    /**
     * The same takeover, for a client that has no PHP session to keep the key in - the mobile app,
     * which is stateless and carries the key itself from `api_quiz_start` onwards.
     *
     * The rule is identical and the two clients dispossess each other: a phone that starts an
     * attempt takes it from the browser tab, and the tab is turned away on its next request.
     */
    public function claimStateless(QuizAttempt $attempt, ?int $position = null): string
    {
        $previous = $attempt->getSessionKey();

        $key = bin2hex(random_bytes(24));
        $attempt->setSessionKey($key);
        $this->entityManager->flush();

        if (null !== $previous) {
            $this->journal->record($attempt, QuizAttemptEventType::TakenOver, $position, QuizEventClient::Mobile);
        }

        return $key;
    }

    /** Whether this browser session is still the one that owns the attempt. */
    public function holds(QuizAttempt $attempt, SessionInterface $session): bool
    {
        $key = $session->get(self::sessionName($attempt));

        return \is_string($key) && $attempt->isHeldBy($key);
    }

    /** The key this browser session holds, to hand to the page so its beacons can authenticate. */
    public function keyFor(QuizAttempt $attempt, SessionInterface $session): ?string
    {
        $key = $session->get(self::sessionName($attempt));

        return \is_string($key) ? $key : null;
    }

    private static function sessionName(QuizAttempt $attempt): string
    {
        return 'quiz_attempt_key_'.$attempt->getId();
    }
}

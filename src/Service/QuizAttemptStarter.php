<?php

namespace App\Service;

use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptAnswer;
use App\Entity\QuizInstance;
use App\Entity\User;
use App\Enum\QuizMode;
use App\Repository\QuizAttemptRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "The student pressed Commencer / S'entraîner": resume the attempt they already had open, or draw
 * a new one - screens 1d (web) and 1k (mobile).
 *
 * A service rather than a private controller method because the web
 * (App\Controller\ProgramQuizAttemptController) and the mobile API
 * (App\Controller\Api\QuizController) both need the exact same four rules, and a second copy of
 * them is a second place for "une seule tentative en évaluation" to quietly stop being true.
 */
class QuizAttemptStarter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuizAttemptRepository $attemptRepository,
        private readonly QuizDrawService $drawService,
    ) {
    }

    /**
     * Returns the attempt to send the student into, or the already-concluded one they may not
     * retake (évaluation) - the caller decides whether that means "question 1" or "your result".
     *
     * @return array{attempt: QuizAttempt, concluded: bool}
     *
     * @throws QuizAttemptNotAllowedException when the quiz is outside its open window
     */
    public function startOrResume(QuizInstance $instance, User $student): array
    {
        $inProgress = $this->attemptRepository->findInProgress($instance, $student);
        if (null !== $inProgress) {
            return ['attempt' => $inProgress, 'concluded' => false];
        }

        // Évaluation grants exactly one attempt; a retry only ever comes from a teacher's
        // "Relancer" (App\Enum\AttemptOrigin::Relance), never from the student pressing Commencer.
        if (QuizMode::Evaluation === $instance->getMode()) {
            $lastConcluded = $this->attemptRepository->findLastConcluded($instance, $student);
            if (null !== $lastConcluded) {
                return ['attempt' => $lastConcluded, 'concluded' => true];
            }
        }

        if (!$instance->isOpenNow()) {
            throw new QuizAttemptNotAllowedException();
        }

        $priorCount = \count($this->attemptRepository->findForStudent($instance, $student));
        $attempt = new QuizAttempt($instance, $student);
        $attempt->setAttemptNumber($priorCount + 1);
        // Capped at a signed 32-bit INT (the column's SQL type) - plenty of entropy for a
        // non-cryptographic deterministic-shuffle seed (see QuizDrawService).
        $attempt->setShuffleSeed(random_int(1, 2_147_483_647));

        // Every question row is created up front, so "the current question" is simply the first
        // unanswered one - see QuizAttemptAnswer's class docblock.
        foreach ($this->drawService->drawQuestions($attempt) as $position => $question) {
            $attemptAnswer = new QuizAttemptAnswer($attempt, $question);
            $attemptAnswer->setOrderIndex($position);
            $attempt->addAttemptAnswer($attemptAnswer);
        }

        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return ['attempt' => $attempt, 'concluded' => false];
    }
}

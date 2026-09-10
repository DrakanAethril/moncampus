<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssignmentSubmission;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Enum\AssignmentFollowUpStatus;

/**
 * One student's line on the teacher's follow-up of one assignment - what
 * App\Service\AssignmentFollowUpBoard hands the screen.
 *
 * The status label travels with the row rather than being asked of the status: the wording belongs
 * to the nature (« Répondu » for a quiz, « Rendu » for a deposit) while the colour belongs to the
 * status, and the screen must not have to know which of the two it is holding.
 */
final class AssignmentFollowUpRow
{
    /** @param list<AssignmentSubmission> $submissions */
    public function __construct(
        public readonly User $student,
        public readonly AssignmentFollowUpStatus $status,
        public readonly string $statusLabelKey,
        public readonly ?\DateTimeImmutable $doneAt = null,
        public readonly array $submissions = [],
        public readonly ?QuizAttempt $attempt = null,
    ) {
    }

    /**
     * The deposit the status reads on - the first one, which is when the student engaged. An
     * assignment spelling out several expected productions holds one submission per production, and
     * the files column reads them all through $submissions.
     */
    public function getSubmission(): ?AssignmentSubmission
    {
        return $this->submissions[0] ?? null;
    }

    public function getScorePercent(): ?float
    {
        return $this->attempt?->getScorePercent();
    }

    /**
     * The mark as the quiz itself counted it - « 12 / 15 », points earned over points available.
     *
     * Read off the attempt and not off the instance: with « mêmes questions pour tous » open, each
     * student is drawn their own set (App\Service\QuizDrawService), and a weighted question makes
     * two draws add up to different totals. The denominator therefore belongs to the attempt, and
     * a screen showing one class-wide total would be writing a fraction nobody was marked on.
     */
    public function getPointsEarnedLabel(): ?string
    {
        return $this->attempt?->getCorrectCountLabel();
    }

    public function getPointsEarned(): ?float
    {
        return $this->attempt?->getCorrectCount();
    }

    public function getPointsAvailable(): ?int
    {
        return $this->attempt?->getQuestionTotal();
    }

    public function getScoreOn20(): ?float
    {
        return $this->attempt?->getScoreOn20();
    }

    /**
     * Whether this line carries a number the carnet de notes could take as a mark. False for a
     * student who never sat the quiz, and false too for an attempt concluded before the score
     * column existed, which has points but nothing to divide them by - « Convertir en note » asks
     * about both rather than counting either as a zero.
     */
    public function hasMark(): bool
    {
        return null !== $this->getPointsEarned() && ($this->getPointsAvailable() ?? 0) > 0;
    }
}

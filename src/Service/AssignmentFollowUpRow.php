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
}

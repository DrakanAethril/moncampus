<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Entity\WordCloudSubmission;
use App\Enum\WordCloudModerationState;
use App\Enum\WordCloudRefusal;
use App\Repository\WordCloudSubmissionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Taking one word from one student, and everything that has to be true for that.
 *
 * The rules live in WordCloudSubmissionPolicy, on primitives; this is what reads the student's own
 * history to build them a context, writes the row, and tells the board a word has arrived.
 */
class WordCloudSubmissionService
{
    public function __construct(
        private readonly WordCloudSubmissionRepository $submissions,
        private readonly WordCloudAggregator $aggregator,
        private readonly WordCloudSubmissionPolicy $policy,
        private readonly WordCloudSchedule $schedule,
        private readonly WordCloudLiveNotifier $liveNotifier,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WordCloudSubmissionRefused when the word is not taken, carrying which rule refused it
     */
    public function submit(WordCloud $cloud, User $student, string $text, \DateTimeImmutable $now): WordCloudSubmission
    {
        $trimmed = trim($text);
        // The key is always the fully normalised form, whatever « Regrouper les variantes » says:
        // it is the duplicate rule's key as well as the counting key, and « chat » then « Chat »
        // from the same person is the same person saying the same thing either way.
        $normalized = $this->aggregator->key($trimmed, true);

        $refusal = $this->policy->refusalFor($trimmed, $normalized, $this->contextFor($cloud, $student, $now));
        if (null !== $refusal) {
            throw new WordCloudSubmissionRefused($refusal);
        }

        $submission = new WordCloudSubmission(
            $cloud,
            $student,
            $trimmed,
            $normalized,
            // The queue is a setting of the cloud, not a stage every word goes through.
            $cloud->isModeration() ? WordCloudModerationState::Pending : WordCloudModerationState::Approved,
        );

        $this->entityManager->persist($submission);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // Two tabs, or a double tap on a slow connection: the policy read a history that was
            // already out of date by the time the insert ran. The database is what settles it, and
            // the answer the student gets is the one they would have got a moment earlier.
            throw new WordCloudSubmissionRefused(WordCloudRefusal::AlreadyProposed, $exception);
        }

        $this->liveNotifier->publish($cloud);

        return $submission;
    }

    private function contextFor(WordCloud $cloud, User $student, \DateTimeImmutable $now): WordCloudSubmissionContext
    {
        $own = $this->submissions->findForCloudAndStudent($cloud, $student);

        $keys = [];
        $counted = 0;
        foreach ($own as $submission) {
            $keys[] = (string) $submission->getNormalizedText();

            // A word the teacher refused does not eat into the quota: they took it out, and making
            // the student pay for it would leave them fewer turns than their neighbour.
            if (WordCloudModerationState::Rejected !== $submission->getModerationState()) {
                ++$counted;
            }
        }

        return new WordCloudSubmissionContext(
            $this->schedule->isOpen($cloud->window(), $now),
            $cloud->getWordLength(),
            $cloud->getWordsPerStudent(),
            $keys,
            $counted,
        );
    }
}

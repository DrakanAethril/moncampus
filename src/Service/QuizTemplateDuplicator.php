<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The deep copy of a quiz, for its own owner or for somebody else.
 *
 * It was a hundred lines inside App\Controller\QuizLibraryController::duplicate() until sharing
 * needed the same write for **another** owner - which is a parameter, not a second path
 * (design/validated/content-sharing-between-teachers.md). Both callers go through here now, so a
 * question type added to one is added to the other.
 *
 * **Question illustrations get a fresh S3 object and no library node.** Two reasons, both wanted:
 * it keeps the recipient's file library free of pictures they never filed, and it leaves their quota
 * untouched - which is coherent with the file library's own « the quota does not bound the
 * platform »: the quota measures the library, not every byte the application holds.
 *
 * Nothing here flushes. The caller owns its unit of work.
 */
class QuizTemplateDuplicator
{
    private const string IMAGE_UPLOAD_PREFIX = 'quiz-question-images/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileUploadService $fileUploadService,
        private readonly MatchingImageStore $matchingImageStore,
    ) {
    }

    /**
     * @param User        $owner the library the copy lands in - the same teacher when duplicating
     *                           one's own, the recipient when duplicating a share
     * @param User        $by    who clicked
     * @param string|null $name  the copy's name; the source's own when null
     */
    public function duplicate(QuizTemplate $source, User $owner, User $by, ?string $name = null): QuizTemplate
    {
        $copy = new QuizTemplate($owner);
        $copy->setName($name ?? (string) $source->getName());
        $copy->setSubject($source->getSubject());
        $copy->setDescription($source->getDescription());
        $copy->setDefaultQuestionCount($source->getDefaultQuestionCount());
        $copy->setDefaultSecondsPerQuestion($source->getDefaultSecondsPerQuestion());
        $copy->setDefaultSameQuestionsForAll($source->isDefaultSameQuestionsForAll());
        $copy->setDefaultQuestionOrderPerStudent($source->isDefaultQuestionOrderPerStudent());
        $copy->setDefaultAnswerOrderPerStudent($source->isDefaultAnswerOrderPerStudent());
        $copy->setCreatedBy($by);

        foreach ($source->getQuestions() as $question) {
            $copy->addQuestion($this->copyQuestion($question, $copy));
        }

        $this->entityManager->persist($copy);

        return $copy;
    }

    private function copyQuestion(QuizQuestion $question, QuizTemplate $copy): QuizQuestion
    {
        $questionCopy = new QuizQuestion($copy);
        $questionCopy->setType($question->getType());
        $questionCopy->setDifficulty($question->getDifficulty());
        $questionCopy->setLabel($question->getLabel());
        $questionCopy->setOrderIndex($question->getOrderIndex());
        $questionCopy->setBlanksConfig($question->getBlanksConfig());
        $questionCopy->setZoneConfig($question->getZoneConfig());
        $questionCopy->setMatchingConfig($this->matchingImageStore->copyImages($question->getMatchingConfig()));
        $questionCopy->setNumericConfig($question->getNumericConfig());
        $questionCopy->setPoints($question->getPoints());
        $questionCopy->setExplanation($question->getExplanation());
        // A copy of a question that is still waiting for its image waits for the same one:
        // duplicating must not quietly turn an incomplete question into a complete-looking one.
        $questionCopy->setExpectedMediaName($question->getExpectedMediaName());

        // A real second object, and deliberately **no** library node: exactly like an image uploaded
        // straight into a question today.
        if (null !== $question->getImageStorageKey()) {
            $newKey = self::IMAGE_UPLOAD_PREFIX.bin2hex(random_bytes(16)).'.'.pathinfo($question->getImageStorageKey(), \PATHINFO_EXTENSION);
            $this->fileUploadService->copy($question->getImageStorageKey(), $newKey);
            $questionCopy->setImageStorageKey($newKey);
        }

        foreach ($question->getAnswers() as $answer) {
            $answerCopy = new QuizAnswer($questionCopy);
            $answerCopy->setLabel($answer->getLabel());
            $answerCopy->setIsCorrect($answer->isCorrect());
            $answerCopy->setOrderIndex($answer->getOrderIndex());
            $questionCopy->addAnswer($answerCopy);
        }

        return $questionCopy;
    }
}

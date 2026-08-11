<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Entity\QuizAnswer;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceAnswer;
use App\Entity\QuizInstanceQuestion;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuizMode;
use App\Enum\QuizScoring;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds a frozen QuizInstance from one or more QuizTemplates + Program + the launch settings
 * chosen on screen 1c - see QuizInstance's class docblock. Mirrors
 * App\Service\SequenceInstantiationService::instantiateSequence() exactly: deep-copies every
 * question/answer (including re-uploading any question image under a fresh S3 key, same as
 * QuizLibraryController::duplicate()) and never touches the source template again afterward.
 *
 * Several templates merge into a single pool, in the order given: their questions are re-indexed
 * sequentially rather than keeping each template's own orderIndex, which all start at 0 and would
 * otherwise interleave the five séances into one shuffled mess (the live concours plays that order
 * literally, so this is not cosmetic).
 *
 * $questionFilter lets a caller copy only part of the bank - the live concours uses it to leave
 * texte à trous questions out (App\Enum\QuestionType::isAvailableInLiveContest()). Filtering here
 * rather than at draw time keeps the instance an honest record of what was actually launched.
 */
class QuizInstantiationService
{
    private const string IMAGE_UPLOAD_PREFIX = 'quiz-question-images/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileUploadService $fileUploadService,
        private readonly QuizDifficultyDistributionResolver $difficultyResolver,
        private readonly MatchingImageStore $matchingImageStore,
    ) {
    }

    /**
     * @param non-empty-list<QuizTemplate> $templates the merged question pool, in launch order
     * @param string|null                  $name      the teacher's own label for this launch;
     *                                                blank falls back to the first template's name
     */
    public function instantiateQuiz(
        array $templates,
        Program $program,
        User $createdBy,
        QuizMode $mode,
        int $questionCount,
        int $difficultySliderPosition,
        bool $sameQuestionsForAll,
        bool $questionOrderPerStudent,
        bool $answerOrderPerStudent,
        ?\DateTimeImmutable $opensAt,
        ?\DateTimeImmutable $closesAt,
        ?int $secondsPerQuestion,
        ?int $globalTimeMinutes,
        QuizScoring $scoring,
        bool $scoreVisibleImmediately,
        ?string $name = null,
        ?\Closure $questionFilter = null,
    ): QuizInstance {
        $firstTemplate = $templates[0];

        $instance = new QuizInstance($program, $createdBy);
        $instance->setSourceTemplate($firstTemplate);
        foreach ($templates as $template) {
            $instance->addSourceTemplate($template);
        }
        $instance->setName('' !== trim((string) $name) ? trim((string) $name) : $firstTemplate->getName());
        // The first template's subject, even on a merge: it is the one the launch started from,
        // and a merged pool has no single subject to compute (the field is free text anyway).
        $instance->setSubject($firstTemplate->getSubject());
        $instance->setMode($mode);
        $instance->setOpensAt($opensAt);
        $instance->setClosesAt($closesAt);
        $instance->setQuestionCount($questionCount);
        $instance->setSameQuestionsForAll($sameQuestionsForAll);
        $instance->setQuestionOrderPerStudent($questionOrderPerStudent);
        $instance->setAnswerOrderPerStudent($answerOrderPerStudent);
        $instance->setSecondsPerQuestion($secondsPerQuestion);
        $instance->setGlobalTimeMinutes($globalTimeMinutes);
        $instance->setScoring($scoring);
        $instance->setScoreVisibleImmediately($scoreVisibleImmediately);

        $percents = $this->difficultyResolver->resolvePercents($difficultySliderPosition);
        $instance->setDifficultyPercents($percents['facilePercent'], $percents['moyenPercent'], $percents['difficilePercent']);
        $counts = $this->difficultyResolver->resolveCounts($percents['facilePercent'], $percents['moyenPercent'], $percents['difficilePercent'], $questionCount);
        $instance->setDifficultyCounts($counts['facile'], $counts['moyen'], $counts['difficile']);

        $orderIndex = 0;
        foreach ($templates as $template) {
            foreach ($template->getQuestions() as $question) {
                if (null !== $questionFilter && !$questionFilter($question)) {
                    continue;
                }
                $instance->addQuestion($this->copyQuestion($question, $instance, $orderIndex++));
            }
        }

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $instance;
    }

    private function copyQuestion(QuizQuestion $question, QuizInstance $instance, int $orderIndex): QuizInstanceQuestion
    {
        $copy = new QuizInstanceQuestion($instance);
        $copy->setType($question->getType());
        $copy->setDifficulty($question->getDifficulty());
        $copy->setLabel($question->getLabel());
        // Frozen like everything else here: editing the library question later must not change how
        // long an already-launched passation gives for it.
        $copy->setTimeMode($question->getTimeMode());
        $copy->setTimeSeconds($question->getTimeSeconds());
        // Re-indexed across the whole merge rather than copied - see the class docblock.
        $copy->setOrderIndex($orderIndex);
        // Frozen like everything else here: editing the template's blanks or zones afterward must
        // not change what an already-launched instance grades against
        // (App\Entity\QuizQuestionDefinitionTrait).
        $copy->setBlanksConfig($question->getBlanksConfig());
        $copy->setZoneConfig($question->getZoneConfig());
        // Its images are re-uploaded rather than shared, exactly like the zones image just below:
        // deleting the library question afterwards must not blank an already-launched passation.
        $copy->setMatchingConfig($this->matchingImageStore->copyImages($question->getMatchingConfig()));
        $copy->setPoints($question->getPoints());
        $copy->setExplanation($question->getExplanation());

        if (null !== $question->getImageStorageKey()) {
            $newKey = self::IMAGE_UPLOAD_PREFIX.bin2hex(random_bytes(16)).'.'.pathinfo($question->getImageStorageKey(), \PATHINFO_EXTENSION);
            $this->fileUploadService->copy($question->getImageStorageKey(), $newKey);
            $copy->setImageStorageKey($newKey);
        }

        foreach ($question->getAnswers() as $answer) {
            $copy->addAnswer($this->copyAnswer($answer, $copy));
        }

        return $copy;
    }

    private function copyAnswer(QuizAnswer $answer, QuizInstanceQuestion $instanceQuestion): QuizInstanceAnswer
    {
        $copy = new QuizInstanceAnswer($instanceQuestion);
        $copy->setLabel($answer->getLabel());
        $copy->setIsCorrect($answer->isCorrect());
        $copy->setOrderIndex($answer->getOrderIndex());

        return $copy;
    }
}

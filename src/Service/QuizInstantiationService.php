<?php

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
 * Builds a frozen QuizInstance from a QuizTemplate + Program + the launch settings chosen on
 * screen 1c - see QuizInstance's class docblock. Mirrors
 * App\Service\SequenceInstantiationService::instantiateSequence() exactly: deep-copies every
 * question/answer (including re-uploading any question image under a fresh S3 key, same as
 * QuizLibraryController::duplicate()) and never touches the source template again afterward.
 */
class QuizInstantiationService
{
    private const string IMAGE_UPLOAD_PREFIX = 'quiz-question-images/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileUploadService $fileUploadService,
        private readonly QuizDifficultyDistributionResolver $difficultyResolver,
    ) {
    }

    public function instantiateQuiz(
        QuizTemplate $template,
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
    ): QuizInstance {
        $instance = new QuizInstance($program, $createdBy);
        $instance->setSourceTemplate($template);
        $instance->setName($template->getName());
        $instance->setSubject($template->getSubject());
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

        foreach ($template->getQuestions() as $question) {
            $instance->addQuestion($this->copyQuestion($question, $instance));
        }

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $instance;
    }

    private function copyQuestion(QuizQuestion $question, QuizInstance $instance): QuizInstanceQuestion
    {
        $copy = new QuizInstanceQuestion($instance);
        $copy->setType($question->getType());
        $copy->setDifficulty($question->getDifficulty());
        $copy->setLabel($question->getLabel());
        $copy->setOrderIndex($question->getOrderIndex());

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

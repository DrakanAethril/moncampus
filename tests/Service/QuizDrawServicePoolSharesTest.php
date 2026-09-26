<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizInstance;
use App\Entity\QuizInstanceQuestion;
use App\Entity\User;
use App\Enum\QuestionDifficulty;
use App\Enum\QuizMode;
use App\Service\QuizDifficultyDistributionResolver;
use App\Service\QuizDrawService;
use PHPUnit\Framework\TestCase;

/**
 * « Part du quiz »: a merged launch whose quizzes were each given a share of the draw.
 */
final class QuizDrawServicePoolSharesTest extends TestCase
{
    private int $nextId = 1;

    public function testEachSharedQuizProvidesItsQuotaWhateverTheSeed(): void
    {
        $instance = $this->instance(10, [['percent' => 70, 'count' => 7], ['percent' => 30, 'count' => 3]]);
        $this->addQuestions($instance, 0, 20);
        $this->addQuestions($instance, 1, 20);

        foreach ([1, 42, 9_999, 123_456] as $seed) {
            self::assertSame([0 => 7, 1 => 3], $this->origins($instance, $seed));
        }
    }

    public function testQuizzesWithoutAShareTakeWhatIsLeft(): void
    {
        $instance = $this->instance(10, [null, ['percent' => 20, 'count' => 2], null]);
        $this->addQuestions($instance, 0, 10);
        $this->addQuestions($instance, 1, 10);
        $this->addQuestions($instance, 2, 10);

        foreach ([3, 77, 5_000] as $seed) {
            $origins = $this->origins($instance, $seed);
            self::assertSame(2, $origins[1] ?? 0);
            self::assertSame(8, ($origins[0] ?? 0) + ($origins[2] ?? 0));
        }
    }

    public function testTheQuotaWinsOverTheDifficultyWhenTheQuizLacksALevel(): void
    {
        // The recipe asks for difficile questions the shared quiz does not have: the quota is still
        // met from its other levels rather than borrowed from the other quiz.
        $instance = $this->instance(10, [['percent' => 50, 'count' => 5], ['percent' => 50, 'count' => 5]]);
        $instance->setDifficultyPercents(10, 30, 60)->setDifficultyCounts(1, 3, 6);
        $this->addQuestions($instance, 0, 10, QuestionDifficulty::Facile);
        $this->addQuestions($instance, 1, 10, QuestionDifficulty::Difficile);

        self::assertSame([0 => 5, 1 => 5], $this->origins($instance, 8));
    }

    public function testNoDuplicateAndTheWholeCountIsDrawn(): void
    {
        $instance = $this->instance(12, [['percent' => 50, 'count' => 6], null]);
        $this->addQuestions($instance, 0, 8);
        $this->addQuestions($instance, 1, 8);

        $drawn = (new QuizDrawService(new QuizDifficultyDistributionResolver()))->drawQuestions($this->attempt($instance, 11));
        $ids = array_map(static fn (QuizInstanceQuestion $question): ?int => $question->getId(), $drawn);

        self::assertCount(12, $drawn);
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    /**
     * @param list<array{percent: int, count: int}|null> $shares
     */
    private function instance(int $questionCount, array $shares): QuizInstance
    {
        $instance = new QuizInstance($this->createStub(Program::class), new User('prof'));
        $instance->setMode(QuizMode::Evaluation);
        $instance->setSameQuestionsForAll(false);
        $instance->setQuestionOrderPerStudent(true);
        $instance->setQuestionCount($questionCount);
        $resolver = new QuizDifficultyDistributionResolver();
        $counts = $resolver->resolveCounts(20, 60, 20, $questionCount);
        $instance->setDifficultyPercents(20, 60, 20)->setDifficultyCounts($counts['facile'], $counts['moyen'], $counts['difficile']);
        $instance->setPoolShares($shares);

        return $instance;
    }

    private function addQuestions(QuizInstance $instance, int $poolIndex, int $count, ?QuestionDifficulty $difficulty = null): void
    {
        $levels = [QuestionDifficulty::Facile, QuestionDifficulty::Moyen, QuestionDifficulty::Difficile];
        for ($i = 0; $i < $count; ++$i) {
            $question = new QuizInstanceQuestion($instance);
            $question->setPoolIndex($poolIndex);
            $question->setDifficulty($difficulty ?? $levels[$i % 3]);
            (new \ReflectionProperty(QuizInstanceQuestion::class, 'id'))->setValue($question, $this->nextId++);
            $instance->addQuestion($question);
        }
    }

    private function attempt(QuizInstance $instance, int $seed): QuizAttempt
    {
        $attempt = new QuizAttempt($instance, new User('etudiant'));
        $attempt->setShuffleSeed($seed);

        return $attempt;
    }

    /** @return array<int, int> questions drawn per pool position */
    private function origins(QuizInstance $instance, int $seed): array
    {
        $drawn = (new QuizDrawService(new QuizDifficultyDistributionResolver()))->drawQuestions($this->attempt($instance, $seed));

        $origins = [];
        foreach ($drawn as $question) {
            $origins[$question->getPoolIndex()] = ($origins[$question->getPoolIndex()] ?? 0) + 1;
        }
        ksort($origins);

        return $origins;
    }
}

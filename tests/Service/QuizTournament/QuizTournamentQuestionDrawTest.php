<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Enum\QuestionType;
use App\Service\QuizTournament\QuizTournamentDrawCandidate;
use App\Service\QuizTournament\QuizTournamentQuestionDraw;
use PHPUnit\Framework\TestCase;

/**
 * The questions of a duel (design/validated/quiz-tournament.md §5.1), on primitives: seeded, twenty
 * questions, at most five vrai/faux, shared between the source quizzes by their size, the questions
 * either player saw in their last three duels left out while the pool allows - and never a failure.
 */
class QuizTournamentQuestionDrawTest extends TestCase
{
    public function testOnlyTheFourDuelFormatsAreAdmitted(): void
    {
        $admitted = array_values(array_filter(QuestionType::cases(), static fn (QuestionType $type): bool => QuizTournamentQuestionDraw::admits($type)));

        self::assertSame([QuestionType::Qcm, QuestionType::QcmMulti, QuestionType::VraiFaux, QuestionType::Numerique], $admitted);
    }

    public function testTheSameSeedDrawsTheSameDuel(): void
    {
        $pool = $this->pool([1 => 30, 2 => 30]);

        self::assertSame(
            QuizTournamentQuestionDraw::draw($pool, [], 20, 5, 1234),
            QuizTournamentQuestionDraw::draw($pool, [], 20, 5, 1234),
        );
        self::assertNotSame(
            QuizTournamentQuestionDraw::draw($pool, [], 20, 5, 1234),
            QuizTournamentQuestionDraw::draw($pool, [], 20, 5, 4321),
        );
    }

    public function testItDrawsTwentyDistinctQuestions(): void
    {
        $drawn = QuizTournamentQuestionDraw::draw($this->pool([1 => 30, 2 => 30]), [], 20, 5, 7);

        self::assertCount(20, $drawn);
        self::assertCount(20, array_unique($drawn));
    }

    public function testNeverMoreThanFiveTrueFalse(): void
    {
        // A pool made mostly of vrai/faux: the cap holds whatever the seed.
        $pool = $this->pool([1 => 20], trueFalseEvery: 1, plain: [1 => 15]);

        for ($seed = 0; $seed < 50; ++$seed) {
            $drawn = QuizTournamentQuestionDraw::draw($pool, [], 20, 5, $seed);

            self::assertCount(20, $drawn);
            self::assertLessThanOrEqual(5, $this->trueFalseCount($pool, $drawn), 'seed '.$seed);
        }
    }

    public function testTheSourcesShareTheDuelByTheirSize(): void
    {
        $pool = $this->pool([1 => 60, 2 => 30, 3 => 10]);
        $drawn = QuizTournamentQuestionDraw::draw($pool, [], 20, 5, 99);

        self::assertSame([1 => 12, 2 => 6, 3 => 2], $this->perSource($pool, $drawn));
    }

    public function testTheRemaindersGoToTheLargestFractions(): void
    {
        // 11 × 7/15 = 5.13, 11 × 5/15 = 3.67, 11 × 3/15 = 2.2: the one left over goes to the second.
        $pool = $this->pool([1 => 7, 2 => 5, 3 => 3]);
        $drawn = QuizTournamentQuestionDraw::draw($pool, [], 11, 5, 3);

        self::assertSame([1 => 5, 2 => 4, 3 => 2], $this->perSource($pool, $drawn));
    }

    public function testQuestionsSeenRecentlyAreLeftOutWhileThePoolAllows(): void
    {
        $pool = $this->pool([1 => 40]);
        $seen = range(1, 20);

        for ($seed = 0; $seed < 20; ++$seed) {
            self::assertSame([], array_intersect($seen, QuizTournamentQuestionDraw::draw($pool, $seen, 20, 5, $seed)), 'seed '.$seed);
        }
    }

    public function testSeenQuestionsComeBackRatherThanFailTheDraw(): void
    {
        $pool = $this->pool([1 => 25]);
        $drawn = QuizTournamentQuestionDraw::draw($pool, range(1, 15), 20, 5, 5);

        self::assertCount(20, $drawn);
        self::assertCount(10, array_diff($drawn, range(1, 15)), 'every unseen question is in');
    }

    public function testASmallPoolGivesAShorterDuelRatherThanFail(): void
    {
        $pool = $this->pool([1 => 8], trueFalseEvery: 2);
        $drawn = QuizTournamentQuestionDraw::draw($pool, [], 20, 2, 5);

        // Four plain questions and two vrai/faux of the four the pool holds.
        self::assertCount(6, $drawn);
        self::assertSame(2, $this->trueFalseCount($pool, $drawn));
        self::assertSame([], QuizTournamentQuestionDraw::draw([], [], 20, 5, 5));
    }

    public function testAShortSourceLeavesItsShareToTheOthers(): void
    {
        // Source 2 is all vrai/faux: once the cap is reached, its share goes to source 1.
        $pool = [
            ...$this->candidates(1, 1, 30, false),
            ...$this->candidates(2, 101, 30, true),
        ];
        $drawn = QuizTournamentQuestionDraw::draw($pool, [], 20, 5, 11);

        self::assertCount(20, $drawn);
        self::assertSame(5, $this->trueFalseCount($pool, $drawn));
    }

    public function testTheReserveCheckNamesWhatIsMissing(): void
    {
        self::assertSame(['missing' => 0, 'missingNotTrueFalse' => 0], QuizTournamentQuestionDraw::reserveShortfall(74, 35, 20, 5));
        self::assertSame(['missing' => 2, 'missingNotTrueFalse' => 0], QuizTournamentQuestionDraw::reserveShortfall(18, 16, 20, 5));
        self::assertSame(['missing' => 0, 'missingNotTrueFalse' => 3], QuizTournamentQuestionDraw::reserveShortfall(40, 12, 20, 5));
    }

    public function testChoicesAreShuffledBySeedAndRankAlike(): void
    {
        $order = QuizTournamentQuestionDraw::choiceOrder(QuestionType::Qcm, [11, 12, 13, 14], 1234, 3);

        self::assertSame($order, QuizTournamentQuestionDraw::choiceOrder(QuestionType::Qcm, [11, 12, 13, 14], 1234, 3));
        self::assertEqualsCanonicalizing([11, 12, 13, 14], $order);

        $orders = [];
        for ($rank = 0; $rank < 20; ++$rank) {
            $orders[] = implode(',', QuizTournamentQuestionDraw::choiceOrder(QuestionType::QcmMulti, [11, 12, 13, 14], 1234, $rank));
        }
        self::assertGreaterThan(1, \count(array_unique($orders)), 'the rank varies the order');
    }

    public function testVraiFauxKeepsItsOrder(): void
    {
        self::assertSame([1, 2], QuizTournamentQuestionDraw::choiceOrder(QuestionType::VraiFaux, [1, 2], 1234, 3));
    }

    /**
     * @param array<int, int> $sizes       source id => number of questions
     * @param array<int, int> $plain       source id => number of non-vrai/faux questions added on top
     *
     * @return list<QuizTournamentDrawCandidate>
     */
    private function pool(array $sizes, int $trueFalseEvery = 0, array $plain = []): array
    {
        $pool = [];
        $next = 1;
        foreach ($sizes as $source => $size) {
            for ($i = 0; $i < $size; ++$i) {
                $pool[] = new QuizTournamentDrawCandidate($next++, $source, $trueFalseEvery > 0 && 0 === $i % $trueFalseEvery);
            }
            for ($i = 0; $i < ($plain[$source] ?? 0); ++$i) {
                $pool[] = new QuizTournamentDrawCandidate($next++, $source, false);
            }
        }

        return $pool;
    }

    /** @return list<QuizTournamentDrawCandidate> */
    private function candidates(int $source, int $firstId, int $count, bool $trueFalse): array
    {
        $candidates = [];
        for ($i = 0; $i < $count; ++$i) {
            $candidates[] = new QuizTournamentDrawCandidate($firstId + $i, $source, $trueFalse);
        }

        return $candidates;
    }

    /**
     * @param list<QuizTournamentDrawCandidate> $pool
     * @param list<int>                         $drawn
     */
    private function trueFalseCount(array $pool, array $drawn): int
    {
        return \count(array_filter($pool, static fn (QuizTournamentDrawCandidate $candidate): bool => $candidate->trueFalse && \in_array($candidate->questionId, $drawn, true)));
    }

    /**
     * @param list<QuizTournamentDrawCandidate> $pool
     * @param list<int>                         $drawn
     *
     * @return array<int, int>
     */
    private function perSource(array $pool, array $drawn): array
    {
        $counts = [];
        foreach ($pool as $candidate) {
            if (\in_array($candidate->questionId, $drawn, true)) {
                $counts[$candidate->sourceId] = ($counts[$candidate->sourceId] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }
}

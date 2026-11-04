<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Enum\QuestionType;
use App\Service\QuizTournament\QuizTournamentDrawCandidate;
use App\Service\QuizTournament\QuizTournamentDuelSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * What a duel freezes of a library question when it starts (design/validated/quiz-tournament.md
 * §5.1), on primitives: the reserve the draw reads, and the choices in the order both players see.
 */
class QuizTournamentDuelSnapshotTest extends TestCase
{
    public function testTheReserveKeepsTheDuelFormatsOnly(): void
    {
        $candidates = QuizTournamentDuelSnapshot::candidates([
            ['questionId' => 1, 'quizId' => 10, 'type' => QuestionType::Qcm],
            ['questionId' => 2, 'quizId' => 10, 'type' => QuestionType::Apparier],
            ['questionId' => 3, 'quizId' => 11, 'type' => QuestionType::VraiFaux],
            ['questionId' => 4, 'quizId' => 11, 'type' => QuestionType::Numerique],
            ['questionId' => 5, 'quizId' => 11, 'type' => QuestionType::QcmMulti],
            ['questionId' => 6, 'quizId' => 11, 'type' => QuestionType::Ordre],
        ]);

        self::assertEquals([
            new QuizTournamentDrawCandidate(1, 10, false),
            new QuizTournamentDrawCandidate(3, 11, true),
            new QuizTournamentDrawCandidate(4, 11, false),
            new QuizTournamentDrawCandidate(5, 11, false),
        ], $candidates);
    }

    public function testChoicesGetLocalIdsInTheQuestionsOwnOrderThenTheSharedDisplayOrder(): void
    {
        $answers = [
            ['label' => 'Nom', 'correct' => true],
            ['label' => 'Couleur préférée', 'correct' => false],
            ['label' => 'Adresse IP', 'correct' => true],
            ['label' => 'Température', 'correct' => false],
        ];

        $choices = QuizTournamentDuelSnapshot::choices(QuestionType::QcmMulti, $answers, 4242, 3);

        self::assertCount(4, $choices);
        $byId = [];
        foreach ($choices as $choice) {
            $byId[$choice['id']] = $choice;
        }
        ksort($byId);
        self::assertSame([1, 2, 3, 4], array_keys($byId));
        self::assertSame(['label' => 'Adresse IP', 'correct' => true], ['label' => $byId[3]['label'], 'correct' => $byId[3]['correct']]);
        self::assertSame($choices, QuizTournamentDuelSnapshot::choices(QuestionType::QcmMulti, $answers, 4242, 3), 'same seed and rank, same order for both players');
    }

    public function testATrueFalseKeepsItsOrderAndANumericQuestionHasNoChoices(): void
    {
        $choices = QuizTournamentDuelSnapshot::choices(QuestionType::VraiFaux, [['label' => 'Vrai', 'correct' => false], ['label' => 'Faux', 'correct' => true]], 99, 0);

        self::assertSame([['id' => 1, 'label' => 'Vrai', 'correct' => false], ['id' => 2, 'label' => 'Faux', 'correct' => true]], $choices);
        self::assertSame([], QuizTournamentDuelSnapshot::choices(QuestionType::Numerique, [], 99, 1));
    }

    public function testASeedFitsTheDuelsUnsignedColumnAndIsNeverZero(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $seed = QuizTournamentDuelSnapshot::newSeed();
            self::assertGreaterThan(0, $seed);
            self::assertLessThanOrEqual(2147483647, $seed);
        }
    }
}

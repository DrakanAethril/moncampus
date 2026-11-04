<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentDuelQuestion;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Enum\QuizTournamentDuelOrigin;
use App\Enum\QuizTournamentPalette;
use App\Service\QuizAnswerChecker;
use App\Service\QuizTournament\QuizTournamentDuelGrader;
use PHPUnit\Framework\TestCase;

/**
 * Grading an answer to a duel's question (design/validated/quiz-tournament.md §5.3): Formatool's own
 * rule (QuizAnswerChecker), one point for a question earned, none otherwise - a QCM à réponses
 * multiples is all-or-nothing on this platform (QuizAttemptGrader), and no penalty ever. An answer
 * that does not fit the question is refused before it is graded.
 */
class QuizTournamentDuelGraderTest extends TestCase
{
    public function testASingleChoiceQuestionTakesExactlyOneOfItsChoices(): void
    {
        self::assertTrue(QuizTournamentDuelGrader::isWellFormed(QuestionType::Qcm, [1, 2, 3], [2], null));
        self::assertFalse(QuizTournamentDuelGrader::isWellFormed(QuestionType::Qcm, [1, 2, 3], [], null));
        self::assertFalse(QuizTournamentDuelGrader::isWellFormed(QuestionType::Qcm, [1, 2, 3], [1, 2], null));
        self::assertFalse(QuizTournamentDuelGrader::isWellFormed(QuestionType::VraiFaux, [1, 2], [7], null));
    }

    public function testAMultipleChoiceQuestionTakesAtLeastOneDistinctChoice(): void
    {
        self::assertTrue(QuizTournamentDuelGrader::isWellFormed(QuestionType::QcmMulti, [1, 2, 3], [1, 3], null));
        self::assertFalse(QuizTournamentDuelGrader::isWellFormed(QuestionType::QcmMulti, [1, 2, 3], [], null));
        self::assertFalse(QuizTournamentDuelGrader::isWellFormed(QuestionType::QcmMulti, [1, 2, 3], [1, 1], null));
        self::assertFalse(QuizTournamentDuelGrader::isWellFormed(QuestionType::QcmMulti, [1, 2, 3], [4], null));
    }

    public function testANumericQuestionTakesANumberAndNoChoice(): void
    {
        self::assertTrue(QuizTournamentDuelGrader::isWellFormed(QuestionType::Numerique, [], [], 72.0));
        self::assertFalse(QuizTournamentDuelGrader::isWellFormed(QuestionType::Numerique, [], [], null));
        self::assertFalse(QuizTournamentDuelGrader::isWellFormed(QuestionType::Numerique, [], [1], 72.0));
        self::assertFalse(QuizTournamentDuelGrader::isWellFormed(QuestionType::Apparier, [], [], null));
    }

    public function testAQcmEarnsOnePointForTheRightChoice(): void
    {
        $question = $this->question(QuestionType::Qcm, [[1, 'A', false], [2, 'B', true], [3, 'C', false]]);
        $grader = new QuizTournamentDuelGrader(new QuizAnswerChecker());

        self::assertSame(1.0, $grader->points($question, [2], null, null));
        self::assertSame(0.0, $grader->points($question, [1], null, null));
    }

    public function testAMultipleChoiceQcmIsAllOrNothing(): void
    {
        $question = $this->question(QuestionType::QcmMulti, [[1, 'Nom', true], [2, 'Couleur', false], [3, 'IP', true]]);
        $grader = new QuizTournamentDuelGrader(new QuizAnswerChecker());

        self::assertSame(1.0, $grader->points($question, [3, 1], null, null));
        self::assertSame(0.0, $grader->points($question, [1], null, null));
        self::assertSame(0.0, $grader->points($question, [1, 2, 3], null, null));
    }

    public function testANumericQuestionReadsItsToleranceAndItsUnit(): void
    {
        $question = $this->question(QuestionType::Numerique, []);
        $question->setNumericConfig(['answer' => 72, 'tolerance' => 0, 'toleranceMode' => 'absolute', 'unit' => 'h', 'unitRequired' => true]);
        $grader = new QuizTournamentDuelGrader(new QuizAnswerChecker());

        self::assertSame(1.0, $grader->points($question, [], 72.0, 'h'));
        self::assertSame(0.0, $grader->points($question, [], 72.0, null));
        self::assertSame(0.0, $grader->points($question, [], 48.0, 'h'));
    }

    /** @param list<array{0: int, 1: string, 2: bool}> $choices */
    private function question(QuestionType $type, array $choices): QuizTournamentDuelQuestion
    {
        $tournament = new QuizTournament('Tournoi', new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2027-02-10'), QuizTournamentPalette::Lavender, new User('admin'));
        $duel = new QuizTournamentDuel($tournament, new User('a'), new User('b'), QuizTournamentDuelOrigin::Challenge, 1, new \DateTimeImmutable('2026-11-12 10:00'), 1000, 1000, false, false);
        $question = new QuizTournamentDuelQuestion($duel, 0, $type, 20);
        $question->setLabel('?');
        $question->setChoices(array_map(static fn (array $c): array => ['id' => $c[0], 'label' => $c[1], 'correct' => $c[2]], $choices));

        return $question;
    }
}

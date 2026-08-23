<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Service\SequenceQuizBoard;
use PHPUnit\Framework\TestCase;

/**
 * The « Quiz de la séquence » card, and the one number it exists for: « 2 séances sur 4 ».
 *
 * That number is why the two relation tables are tables and not two nullable foreign keys. It measures
 * **usage**, not provenance, and the two do not have the same cardinality: a quiz is produced from one
 * séance once, but a réactivation quiz serves in S2 *and* in S3, and a séance happily carries a
 * diagnostic at its opening and a final at its end. With one FK per level, making a quiz serve two
 * séances means duplicating it - which is exactly what a library exists to avoid.
 *
 * Two tables rather than one with a nullable seance_template_id, for a reason the Ansible kit supplies:
 * its `qcm-final.md` is about the whole séquence and about no séance in particular. A half-absent key
 * is a table that means two things.
 *
 * The card lists one row per quiz, séance-level ones raised to the séquence and deduplicated, each row
 * naming where it hangs. Coverage counts *séances*, and deliberately not the séquence-level quizzes: a
 * final exam attached to the whole séquence must not make four séances look covered.
 */
class SequenceQuizBoardTest extends TestCase
{
    private SequenceQuizBoard $board;

    protected function setUp(): void
    {
        $this->board = new SequenceQuizBoard();
    }

    public function testAnUntouchedSequenceIsCoveredNowhere(): void
    {
        $sequence = $this->sequence(4);

        $board = $this->board->forSequence($sequence);

        self::assertSame(0, $board['coveredSeances']);
        self::assertSame(4, $board['totalSeances']);
        self::assertSame([], $board['quizzes']);
        self::assertFalse($board['hasAnyQuiz']);
    }

    public function testASeanceWithAQuizIsCountedAndRaisedToTheSequence(): void
    {
        $sequence = $this->sequence(4);
        $this->attachToSeance($sequence, 0, 'Diagnostic Ansible');
        $this->attachToSeance($sequence, 2, 'Playbooks');

        $board = $this->board->forSequence($sequence);

        self::assertSame(2, $board['coveredSeances']);
        self::assertSame(4, $board['totalSeances']);
        self::assertSame(['Diagnostic Ansible', 'Playbooks'], $this->names($board['quizzes']));
        self::assertFalse($board['quizzes'][0]['onSequence']);
        self::assertSame(['Séance 1'], array_column($board['quizzes'][0]['seances'], 'titre'));
    }

    /** A séance carries a diagnostic at its opening and a final at its end - and counts once. */
    public function testASeanceWithTwoQuizzesStillCountsOnce(): void
    {
        $sequence = $this->sequence(2);
        $this->attachToSeance($sequence, 0, 'Diagnostic');
        $this->attachToSeance($sequence, 0, 'Bilan');

        $board = $this->board->forSequence($sequence);

        self::assertSame(1, $board['coveredSeances']);
        self::assertCount(2, $board['quizzes']);
    }

    /**
     * The same quiz serving two séances is the case the relation tables exist for. Nothing is
     * duplicated - one row naming both séances - and both séances count.
     */
    public function testOneQuizServingTwoSeancesIsOneRowNamingBoth(): void
    {
        $sequence = $this->sequence(3);
        $reactivation = $this->quiz('Réactivation');
        foreach ([1, 2] as $index) {
            $reactivation->addSeanceTemplate($this->seance($sequence, $index));
        }

        $board = $this->board->forSequence($sequence);

        self::assertSame(2, $board['coveredSeances']);
        self::assertCount(1, $board['quizzes']);
        self::assertSame([2, 3], array_column($board['quizzes'][0]['seances'], 'ordre'));
    }

    /** Attached to the séquence *and* to one of its séances: still one row, saying both. */
    public function testAQuizAttachedToBothLevelsIsListedOnce(): void
    {
        $sequence = $this->sequence(2);
        $quiz = $this->quiz('QCM final');
        $quiz->addSequenceTemplate($sequence);
        $quiz->addSeanceTemplate($this->seance($sequence, 1));

        $board = $this->board->forSequence($sequence);

        self::assertCount(1, $board['quizzes']);
        self::assertTrue($board['quizzes'][0]['onSequence']);
        self::assertSame(['Séance 2'], array_column($board['quizzes'][0]['seances'], 'titre'));
        self::assertSame(1, $board['coveredSeances']);
    }

    /**
     * A quiz on the whole séquence is listed on its own and covers no séance. The kit's final QCM is
     * exactly this, and letting it count would report four covered séances for a séquence whose
     * séances have no questions at all.
     */
    public function testASequenceLevelQuizIsListedAndCoversNoSeance(): void
    {
        $sequence = $this->sequence(4);
        $this->quiz('QCM final')->addSequenceTemplate($sequence);

        $board = $this->board->forSequence($sequence);

        self::assertSame(['QCM final'], $this->names($board['quizzes']));
        self::assertTrue($board['quizzes'][0]['onSequence']);
        self::assertSame([], $board['quizzes'][0]['seances']);
        self::assertSame(0, $board['coveredSeances'], 'a séquence-level quiz covers no séance');
    }

    /** A séquence with no séance at all must not divide by zero on its way to a percentage. */
    public function testASequenceWithoutASingleSeanceIsAnsweredRatherThanDivided(): void
    {
        $board = $this->board->forSequence($this->sequence(0));

        self::assertSame(0, $board['totalSeances']);
        self::assertSame(0, $board['coveredSeances']);
        self::assertFalse($board['hasAnyQuiz']);
    }

    public function testHasAnyQuizIsTrueForASequenceLevelQuizAlone(): void
    {
        $sequence = $this->sequence(2);
        $this->quiz('QCM final')->addSequenceTemplate($sequence);

        self::assertTrue($this->board->forSequence($sequence)['hasAnyQuiz']);
    }

    public function testTheSeanceCardTellsWhichQuizzesTheSequenceAlsoNames(): void
    {
        $sequence = $this->sequence(2);
        $seance = $this->seance($sequence, 0);
        $both = $this->quiz('QCM final');
        $both->addSequenceTemplate($sequence);
        $both->addSeanceTemplate($seance);
        $this->quiz('Diagnostic')->addSeanceTemplate($seance);

        $board = $this->board->forSeance($seance);

        self::assertSame(['QCM final', 'Diagnostic'], $this->names($board['quizzes']));
        self::assertTrue($board['quizzes'][0]['onSequence']);
        self::assertFalse($board['quizzes'][1]['onSequence']);
    }

    public function testASeanceWithoutAQuizIsAnEmptyCard(): void
    {
        $board = $this->board->forSeance($this->seance($this->sequence(1), 0));

        self::assertSame([], $board['quizzes']);
    }

    private function sequence(int $seances): SequenceTemplate
    {
        $sequence = new SequenceTemplate(new User('prof-001'));
        $sequence->setTitre('Automatisation avec Ansible');

        for ($index = 1; $index <= $seances; ++$index) {
            $seance = new SeanceTemplate($sequence);
            $seance->setTitre('Séance '.$index);
            $seance->setOrdre($index);
            $sequence->getSeanceTemplates()->add($seance);
        }

        return $sequence;
    }

    private function seance(SequenceTemplate $sequence, int $index): SeanceTemplate
    {
        $seance = $sequence->getSeanceTemplates()->get($index);
        self::assertInstanceOf(SeanceTemplate::class, $seance);

        return $seance;
    }

    private function quiz(string $name): QuizTemplate
    {
        $quiz = new QuizTemplate(new User('prof-001'));
        $quiz->setName($name);

        return $quiz;
    }

    private function attachToSeance(SequenceTemplate $sequence, int $index, string $name): void
    {
        $this->quiz($name)->addSeanceTemplate($this->seance($sequence, $index));
    }

    /**
     * @param list<array{quiz: QuizTemplate, ...}> $rows
     *
     * @return list<string>
     */
    private function names(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['quiz']->getName(), $rows);
    }
}

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
 * Coverage counts *séances*, and deliberately not the séquence-level quizzes: a final exam attached to
 * the whole séquence must not make four séances look covered.
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
        self::assertSame([], $board['sequenceQuizzes']);
        self::assertCount(4, $board['seances']);
        foreach ($board['seances'] as $row) {
            self::assertSame([], $row['quizzes']);
        }
    }

    public function testASeanceWithAQuizIsCounted(): void
    {
        $sequence = $this->sequence(4);
        $this->attachToSeance($sequence, 0, 'Diagnostic Ansible');
        $this->attachToSeance($sequence, 2, 'Playbooks');

        $board = $this->board->forSequence($sequence);

        self::assertSame(2, $board['coveredSeances']);
        self::assertSame(4, $board['totalSeances']);
        self::assertSame(['Diagnostic Ansible'], array_map($this->quizName(), $board['seances'][0]['quizzes']));
        self::assertSame([], $board['seances'][1]['quizzes']);
        self::assertSame(['Playbooks'], array_map($this->quizName(), $board['seances'][2]['quizzes']));
    }

    /** A séance carries a diagnostic at its opening and a final at its end - and counts once. */
    public function testASeanceWithTwoQuizzesStillCountsOnce(): void
    {
        $sequence = $this->sequence(2);
        $this->attachToSeance($sequence, 0, 'Diagnostic');
        $this->attachToSeance($sequence, 0, 'Bilan');

        $board = $this->board->forSequence($sequence);

        self::assertSame(1, $board['coveredSeances']);
        self::assertCount(2, $board['seances'][0]['quizzes']);
    }

    /**
     * The same quiz serving two séances is the case the relation tables exist for. Nothing is
     * duplicated, and both séances count.
     */
    public function testOneQuizCanServeTwoSeancesWithoutBeingDuplicated(): void
    {
        $sequence = $this->sequence(3);
        $reactivation = $this->quiz('Réactivation');
        foreach ([1, 2] as $index) {
            $seance = $sequence->getSeanceTemplates()->get($index);
            self::assertInstanceOf(SeanceTemplate::class, $seance);
            $reactivation->addSeanceTemplate($seance);
        }

        $board = $this->board->forSequence($sequence);

        self::assertSame(2, $board['coveredSeances']);
        self::assertSame($board['seances'][1]['quizzes'][0], $board['seances'][2]['quizzes'][0], 'the same row, not a copy');
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

        self::assertSame(['QCM final'], array_map($this->quizName(), $board['sequenceQuizzes']));
        self::assertSame(0, $board['coveredSeances'], 'a séquence-level quiz covers no séance');
    }

    public function testTheSeanceRowsKeepTheDeroulesOrderAndNameThemselves(): void
    {
        $board = $this->board->forSequence($this->sequence(3));

        self::assertSame(['Séance 1', 'Séance 2', 'Séance 3'], array_column($board['seances'], 'titre'));
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

    private function quiz(string $name): QuizTemplate
    {
        $quiz = new QuizTemplate(new User('prof-001'));
        $quiz->setName($name);

        return $quiz;
    }

    private function attachToSeance(SequenceTemplate $sequence, int $index, string $name): void
    {
        $seance = $sequence->getSeanceTemplates()->get($index);
        self::assertInstanceOf(SeanceTemplate::class, $seance);
        $this->quiz($name)->addSeanceTemplate($seance);
    }

    /** @return \Closure(QuizTemplate): string */
    private function quizName(): \Closure
    {
        return static fn (QuizTemplate $quiz): string => (string) $quiz->getName();
    }
}

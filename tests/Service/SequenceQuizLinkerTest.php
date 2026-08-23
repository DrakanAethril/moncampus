<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Service\SequenceQuizLinker;
use PHPUnit\Framework\TestCase;

/**
 * The two levels a quiz can be attached to, and the asymmetry between their « Détacher ».
 *
 * The séquence's card lists every quiz used anywhere in the séquence, deduplicated, so its detach
 * speaks for the whole séquence and reaches the séances. The séance's speaks for one séance only.
 * Neither ever deletes a quiz: the library is its home.
 */
class SequenceQuizLinkerTest extends TestCase
{
    private SequenceQuizLinker $linker;

    protected function setUp(): void
    {
        $this->linker = new SequenceQuizLinker();
    }

    public function testAttachingTwiceLeavesOneLink(): void
    {
        $sequence = $this->sequence(2);
        $quiz = $this->quiz('QCM final');

        $this->linker->attachToSequence($quiz, $sequence);
        $this->linker->attachToSequence($quiz, $sequence);

        self::assertCount(1, $quiz->getSequenceTemplates());
        self::assertCount(1, $sequence->getQuizTemplates(), 'the inverse side is kept in step');
    }

    public function testDetachingFromTheSequenceReachesItsSeances(): void
    {
        $sequence = $this->sequence(3);
        $quiz = $this->quiz('Réactivation');
        $this->linker->attachToSequence($quiz, $sequence);
        $this->linker->attachToSeance($quiz, $this->seance($sequence, 0));
        $this->linker->attachToSeance($quiz, $this->seance($sequence, 2));

        $this->linker->detachFromSequence($quiz, $sequence);

        self::assertCount(0, $quiz->getSequenceTemplates());
        self::assertCount(0, $quiz->getSeanceTemplates());
        self::assertCount(0, $this->seance($sequence, 0)->getQuizTemplates());
    }

    /** A quiz attached to a séance alone still goes away through the séquence's own « Détacher ». */
    public function testDetachingFromTheSequenceReachesASeanceOnlyQuiz(): void
    {
        $sequence = $this->sequence(2);
        $quiz = $this->quiz('Diagnostic');
        $this->linker->attachToSeance($quiz, $this->seance($sequence, 1));

        $this->linker->detachFromSequence($quiz, $sequence);

        self::assertCount(0, $quiz->getSeanceTemplates());
    }

    /** Another séquence's séances are none of this détachement's business. */
    public function testDetachingFromOneSequenceLeavesAnotherSequenceAlone(): void
    {
        $ansible = $this->sequence(2);
        $reseau = $this->sequence(2);
        $quiz = $this->quiz('Réactivation');
        $this->linker->attachToSeance($quiz, $this->seance($ansible, 0));
        $this->linker->attachToSeance($quiz, $this->seance($reseau, 1));

        $this->linker->detachFromSequence($quiz, $ansible);

        self::assertCount(1, $quiz->getSeanceTemplates());
        self::assertSame($this->seance($reseau, 1), $quiz->getSeanceTemplates()->first());
    }

    public function testDetachingFromASeanceKeepsTheSequenceLink(): void
    {
        $sequence = $this->sequence(2);
        $quiz = $this->quiz('QCM final');
        $this->linker->attachToSequence($quiz, $sequence);
        $this->linker->attachToSeance($quiz, $this->seance($sequence, 0));

        $this->linker->detachFromSeance($quiz, $this->seance($sequence, 0));

        self::assertCount(0, $quiz->getSeanceTemplates());
        self::assertCount(1, $quiz->getSequenceTemplates(), 'the séquence link was named separately and stays');
    }

    /** Detaching from a séance the quiz never served changes nothing, rather than failing. */
    public function testDetachingFromAnUnrelatedSeanceIsANoOp(): void
    {
        $sequence = $this->sequence(2);
        $quiz = $this->quiz('Diagnostic');
        $this->linker->attachToSeance($quiz, $this->seance($sequence, 0));

        $this->linker->detachFromSeance($quiz, $this->seance($sequence, 1));

        self::assertCount(1, $quiz->getSeanceTemplates());
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
}

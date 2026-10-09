<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\Grade;
use App\Entity\GradeRubricAnswer;
use App\Entity\User;
use App\Enum\GradeStatus;
use App\Repository\GradeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * What typing in the carnet de notes writes, in its two modes: the overall cell of an evaluation
 * without barème (a status and a value, read by App\Service\GradeEntryParser), and one question's
 * box of an evaluation with one (points read by App\Service\RubricPointsParser, the row's total
 * recomputed from every box by App\Service\EvaluationAverageCalculator).
 *
 * It is the one door a grade is written through - the entry screen, the grid and the Claude
 * connector all come this way - and it decides nothing about *who* may write: every caller asks
 * App\Security\Voter\EvaluationVoter::MANAGE first, and checks that the student is the class's.
 *
 * **It never flushes.** A caller writing a whole class hands each student's row back in (`$grade`)
 * so that a row created a moment ago, which no query can see yet, is the one written again.
 */
final readonly class GradeEntryWriter
{
    public function __construct(
        private GradeRepository $grades,
        private EntityManagerInterface $entityManager,
        private GradeEntryParser $cells,
        private RubricPointsParser $boxes,
        private EvaluationAverageCalculator $calculator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The overall cell. An emptied cell - or one holding nothing usable - erases the grade rather
     * than storing a zero.
     *
     * @return Grade|null the student's grade, null when the cell was emptied
     */
    public function writeCell(Evaluation $evaluation, User $student, string $raw, User $author, ?Grade $grade = null): ?Grade
    {
        [$status, $value] = $this->cells->parse($raw, $evaluation->getScale());
        $grade ??= $this->grades->findOneForEvaluationAndStudent($evaluation, $student);

        if (null === $status) {
            if (null !== $grade) {
                $this->entityManager->remove($grade);
            }

            return null;
        }

        if (null === $grade) {
            $grade = new Grade($evaluation, $student);
            $this->entityManager->persist($grade);
        }

        $grade->setStatus($status)->setValue($value);
        $this->stamp($grade, $author);

        return $grade;
    }

    /**
     * One question's box. The question is one of this evaluation's own - the caller found it there.
     *
     * @throws InvalidRubricPoints before anything is created, so a refused box leaves no empty row
     */
    public function writeAnswer(Evaluation $evaluation, User $student, EvaluationRubricQuestion $question, string $raw, User $author, ?Grade $grade = null): Grade
    {
        $box = $this->boxes->parse($raw, $question->getMaxPoints());
        $grade ??= $this->grades->findOneForEvaluationAndStudent($evaluation, $student);

        if (null === $grade) {
            $grade = new Grade($evaluation, $student);
            $grade->setStatus(GradeStatus::Normal);
            $this->entityManager->persist($grade);
        }

        $answer = null;
        foreach ($grade->getRubricAnswers() as $candidate) {
            if ($candidate->getQuestion() === $question) {
                $answer = $candidate;
            }
        }
        if (null === $answer) {
            $answer = new GradeRubricAnswer($grade, $question);
            $grade->addRubricAnswer($answer);
            $this->entityManager->persist($answer);
        }

        $answer->setPointsAwarded($box['points'])->setNotTested($box['notTested']);
        $grade->setValue($this->calculator->computeRubricTotal($grade));
        $this->stamp($grade, $author);

        return $grade;
    }

    private function stamp(Grade $grade, User $author): void
    {
        $grade->setGradedBy($author)->setGradedAt($this->clock->now());
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\Evaluation;
use App\Entity\Grade;
use App\Entity\User;
use App\Enum\AssignmentMissingGradeChoice;
use App\Repository\GradeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a travail carrying a quiz into an evaluation of the carnet de notes - « Convertir en note »
 * on the follow-up screen.
 *
 * The counterpart of App\Service\AssignmentGradebookLinker, which does the same for a dépôt and
 * stops there: it creates an empty evaluation on reception of the first rendu, the teacher entering
 * every mark by hand afterwards. A quiz already holds the mark, so here the grades come with the
 * evaluation.
 *
 * Two things it deliberately does not decide. Which attempt makes the mark is not asked again: the
 * rows come from App\Service\AssignmentFollowUpBoard, so the number written in the carnet is the
 * one already printed on screen - the first attempt reaching the objectif minimum, failing that the
 * last. And what to do with a student who never sat it is not guessed: the arithmetic lives in
 * AssignmentGradeConversionPlanner, which names them rather than inventing a zero.
 *
 * A second conversion rewrites every grade from the quiz. That is the point of the gesture - a
 * latecomer who sat it after the first pass replaces the « Absent » written for them - and it is
 * also what the modal warns about, a mark retouched by hand in the carnet not surviving it.
 */
class AssignmentQuizGradeConverter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GradeRepository $gradeRepository,
        private readonly AssignmentGradeConversionPlanner $planner,
    ) {
    }

    /**
     * The plan alone, written nowhere: what the screen needs to know whether it may convert at all.
     *
     * @param list<AssignmentFollowUpRow>             $rows
     * @param array<int, AssignmentMissingGradeChoice> $missingChoices
     */
    public function plan(array $rows, float $scale, array $missingChoices): AssignmentGradeConversionPlan
    {
        return $this->planner->plan(
            array_map(static fn (AssignmentFollowUpRow $row): array => [
                'studentId' => (int) $row->student->getId(),
                'pointsEarned' => $row->getPointsEarned(),
                'pointsAvailable' => $row->getPointsAvailable(),
            ], $rows),
            $scale,
            $missingChoices,
        );
    }

    /**
     * @param list<AssignmentFollowUpRow>              $rows
     * @param array<int, AssignmentMissingGradeChoice> $missingChoices
     */
    public function convert(Assignment $assignment, array $rows, AssignmentGradeConversionSettings $settings, array $missingChoices, User $author): Evaluation
    {
        $plan = $this->plan($rows, $settings->scale, $missingChoices);
        $evaluation = $this->evaluationFor($assignment, $settings, $author);

        /** @var array<int, User> $studentsById */
        $studentsById = [];
        foreach ($rows as $row) {
            $studentsById[(int) $row->student->getId()] = $row->student;
        }

        // Only an evaluation that already exists can hold grades - a brand new one has no
        // identifier yet, and Doctrine refuses to bind it as a query parameter at all.
        /** @var array<int, Grade> $existingByStudentId */
        $existingByStudentId = [];
        if (null !== $evaluation->getId()) {
            foreach ($this->gradeRepository->findForEvaluation($evaluation) as $grade) {
                $student = $grade->getStudent();
                if (null !== $student) {
                    $existingByStudentId[(int) $student->getId()] = $grade;
                }
            }
        }

        $now = new \DateTimeImmutable();

        foreach ($plan->cells as $cell) {
            $student = $studentsById[$cell['studentId']] ?? null;
            if (null === $student) {
                continue;
            }

            $grade = $existingByStudentId[$cell['studentId']] ?? null;

            if (null === $grade) {
                $grade = new Grade($evaluation, $student);
                $this->entityManager->persist($grade);
            }

            $grade->setStatus($cell['status'])->setValue($cell['value'])->setGradedBy($author)->setGradedAt($now);
        }

        $this->entityManager->flush();

        return $evaluation;
    }

    /**
     * The evaluation the grades hang off: the one this travail is already linked to, or a new one.
     *
     * An evaluation the teacher has since removed from the carnet (inactivated) is not resurrected -
     * it was taken out on purpose, and writing marks back into a row nobody sees any more would make
     * the button do nothing visible. The travail is relinked to a fresh one instead.
     */
    private function evaluationFor(Assignment $assignment, AssignmentGradeConversionSettings $settings, User $author): Evaluation
    {
        $evaluation = $assignment->getGradebookEvaluation();

        if (null === $evaluation || null !== $evaluation->getInactiveDate()) {
            $evaluation = new Evaluation($settings->topic, $settings->name, $settings->date);
            $evaluation->setCreatedBy($author);
            $this->entityManager->persist($evaluation);
            $assignment->setGradebookEvaluation($evaluation);
        } else {
            $evaluation->setTopic($settings->topic);
            $evaluation->setName($settings->name);
            $evaluation->setDate($settings->date);
            $evaluation->setLastUpdatedBy($author);
            $evaluation->setLastUpdatedDate(new \DateTimeImmutable());
        }

        $evaluation
            ->setType($settings->type)
            ->setNature($settings->nature)
            ->setModality($settings->modality)
            ->setStatus($settings->status)
            ->setScale($settings->scale)
            ->setCoefficient($settings->coefficient)
            ->setCountsOutOf20($settings->countsOutOf20)
            ->setVisibleAt($settings->visibleAt)
            // The créneau the travail was given from, when it has one: it is what lets the
            // progression's calendars place the evaluation in the right day column.
            ->setLessonSession($assignment->getLessonSession());

        // The matière is settled by the modal, so a travail that had none carries it from now on -
        // the next conversion, and every screen reading the travail's matière, find it there.
        $assignment->setTopic($settings->topic);

        return $evaluation;
    }
}

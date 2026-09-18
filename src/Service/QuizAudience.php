<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\User;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\QuizAttemptRepository;

/**
 * "Who is this launched quiz addressed to?" - the whole class, or the students of the one option it
 * was narrowed to at launch (QuizInstance::$visibilityOption).
 *
 * One service rather than the same `null === getVisibilityOption()` ternary in four places, and for
 * a reason the surveys already state about their own SurveyTarget: the audience is the denominator.
 * A quiz limited to an option that still listed the whole class on the results screen would print a
 * participation rate against students who were never shown it, and « 12 / 30 » would be a wrong
 * number rather than an incomplete one.
 *
 * It answers on the platform's own rows only - ProgramStudentOption, the link between a class's
 * students and its options (Paramétrage > Membres). A student the annuaire has not yet placed in an
 * option is therefore outside a narrowed quiz, which is the same thing the option-scoped travail à
 * faire already does (App\Service\AssignmentAudienceResolver).
 *
 * One exception, and it is what makes the setting editable after the launch: **the narrowing decides
 * who may discover and begin a quiz, never who has already begun it**. A student holding an attempt
 * keeps the quiz on their hub and keeps their copy, whatever the audience says today.
 */
class QuizAudience
{
    public function __construct(
        private readonly ProgramStudentOptionRepository $studentOptions,
        private readonly QuizAttemptRepository $attempts,
    ) {
    }

    /**
     * Everybody this quiz concerns: the option's students, plus anybody holding an attempt on it -
     * which is the same rule includes() applies, read the other way round.
     *
     * The second half is not a courtesy. Narrowing a quiz after the launch must not erase a student
     * from the screen that holds their copy, and above all must not erase the one who is sitting it
     * at that moment: the teacher would then be watching a row that is not there while somebody
     * finishes, with no « Relancer » and no supervision timeline to reach them by.
     *
     * @return list<User>
     */
    public function students(QuizInstance $instance): array
    {
        $program = $instance->getProgram();

        if (null === $program) {
            return [];
        }

        $option = $instance->getVisibilityOption();

        if (null === $option) {
            return array_values($program->getStudents()->toArray());
        }

        // Intersected with the class roster rather than taken from the link rows alone: a student
        // who has left the class since keeps their ProgramStudentOption row, and the results screen
        // must not grow a line for somebody who is no longer there.
        $roster = $program->getStudents();
        $concerned = [];
        foreach ($this->studentOptions->findStudentsForProgramAndOptions($program, [$option]) as $student) {
            if ($roster->contains($student)) {
                $concerned[(int) $student->getId()] = $student;
            }
        }
        foreach ($this->attempts->findStudentsWithAttempt($instance) as $student) {
            if ($roster->contains($student)) {
                $concerned[(int) $student->getId()] ??= $student;
            }
        }

        return array_values($concerned);
    }

    /**
     * The same question asked at the door, of a single quiz - deliberately its own query rather than
     * a memo kept between calls: this container is a FrankenPHP worker, and a service that remembers
     * without resetting answers the next request with the previous student's options.
     */
    public function includes(QuizInstance $instance, User $student): bool
    {
        $option = $instance->getVisibilityOption();

        if (null === $option) {
            return true;
        }

        $program = $instance->getProgram();

        // Only the narrowing is answered here, never "is this person in the class" - the routes that
        // ask already establish that (App\Controller\ProgramQuizAttemptController's own
        // findProgramForStudentOrNotFound(), and the API's equivalent). Re-checking it would turn
        // this into a second, weaker membership rule in a place nobody would think to look.
        if (null !== $program && \in_array($option, $this->studentOptions->findOptionsForStudent($program, $student), true)) {
            return true;
        }

        return $this->hasSatIt($instance, $student);
    }

    /**
     * The narrowing never applies to somebody who has already begun.
     *
     * It is what makes the setting editable after the launch (App\Form\QuizInstanceEditType): a
     * teacher who narrows a quiz to SLAM once the class has sat it must not take the SISR students'
     * own copies away from them, and one who does it while somebody is mid-quiz must not lock that
     * student out of the page they are on. Same rule QuizAttemptStarter already applies to a window
     * that shuts mid-quiz - it is finished, not interrupted.
     */
    private function hasSatIt(QuizInstance $instance, User $student): bool
    {
        return [] !== $this->attempts->findForStudent($instance, $student);
    }

    /**
     * The narrowing applied to a whole list at once, for the student's hub: the reader's options are
     * read once for the class instead of once per quiz.
     *
     * @param list<QuizInstance> $instances
     *
     * @return list<QuizInstance>
     */
    public function readableBy(Program $program, array $instances, User $student): array
    {
        $narrowed = array_filter($instances, static fn (QuizInstance $instance): bool => null !== $instance->getVisibilityOption());

        if ([] === $narrowed) {
            return $instances;
        }

        $held = $this->studentOptions->findOptionsForStudent($program, $student);
        // One query for the whole list, not one per narrowed quiz: a quiz this student has already
        // sat stays theirs whatever the narrowing says now (see hasSatIt()).
        $satIds = $this->attempts->findAttemptedInstanceIds(array_values($narrowed), $student);

        return array_values(array_filter(
            $instances,
            static function (QuizInstance $instance) use ($held, $satIds): bool {
                $option = $instance->getVisibilityOption();

                return null === $option
                    || \in_array($option, $held, true)
                    || \in_array((int) $instance->getId(), $satIds, true);
            },
        ));
    }

    /**
     * How many students the quiz concerns - the denominator of every « n / m » printed on it, and
     * the same set students() lists, so a screen's count and its rows can never disagree.
     */
    public function count(QuizInstance $instance): int
    {
        $option = $instance->getVisibilityOption();

        if (null === $option) {
            return $instance->getProgram()?->getStudents()->count() ?? 0;
        }

        return \count($this->students($instance));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assignment;
use App\Entity\Evaluation;
use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Enum\AssignmentNature;
use App\Service\AssignmentNatureRequirements;
use PHPUnit\Framework\TestCase;

/**
 * What a travail à faire must carry before it can be saved, according to its nature.
 *
 * The wizard already blocks most of this in the browser, which is precisely why the rule needs a
 * test: nothing exercises the server-side net until a request arrives that did not come from the
 * screen. A quiz assignment without a quiz, or a self-assessment without an evaluation, would be a
 * row a student can open and not do.
 */
class AssignmentNatureRequirementsTest extends TestCase
{
    private AssignmentNatureRequirements $requirements;

    protected function setUp(): void
    {
        $this->requirements = new AssignmentNatureRequirements();
    }

    public function testAQuizAssignmentNeedsAQuiz(): void
    {
        $assignment = $this->assignment(AssignmentNature::Quiz, withProgram: true);

        self::assertSame(
            ['quizInstance' => 'assignmentWizardQuizRequiredMessage'],
            $this->requirements->missing($assignment),
        );
    }

    public function testAQuizAssignmentThatCarriesItsQuizIsComplete(): void
    {
        $assignment = $this->assignment(AssignmentNature::Quiz, withProgram: true);
        $assignment->setQuizInstance((new \ReflectionClass(QuizInstance::class))->newInstanceWithoutConstructor());

        self::assertSame([], $this->requirements->missing($assignment));
    }

    public function testASelfAssessmentNeedsAnEvaluation(): void
    {
        $assignment = $this->assignment(AssignmentNature::SelfAssessment, withProgram: true);

        self::assertSame(
            ['evaluation' => 'assignmentWizardEvaluationRequiredMessage'],
            $this->requirements->missing($assignment),
        );
    }

    public function testASelfAssessmentThatCarriesItsEvaluationIsComplete(): void
    {
        $assignment = $this->assignment(AssignmentNature::SelfAssessment, withProgram: true);
        $assignment->setEvaluation((new \ReflectionClass(Evaluation::class))->newInstanceWithoutConstructor());

        self::assertSame([], $this->requirements->missing($assignment));
    }

    public function testAClassIsAlwaysRequired(): void
    {
        // The screen blocks step 1 without one; this is the net for a request that skipped it.
        $assignment = $this->assignment(AssignmentNature::Exercices, withProgram: false);

        self::assertSame(
            ['program' => 'assignmentWizardClassRequiredMessage'],
            $this->requirements->missing($assignment),
        );
    }

    public function testEveryMissingFieldIsReportedAtOnce(): void
    {
        // Not one error at a time: the wizard shows them all on the step they belong to.
        $assignment = $this->assignment(AssignmentNature::Quiz, withProgram: false);

        self::assertSame(
            ['quizInstance' => 'assignmentWizardQuizRequiredMessage', 'program' => 'assignmentWizardClassRequiredMessage'],
            $this->requirements->missing($assignment),
        );
    }

    public function testAnOrdinaryAssignmentNeedsNeitherQuizNorEvaluation(): void
    {
        $assignment = $this->assignment(AssignmentNature::Exercices, withProgram: true);

        self::assertSame([], $this->requirements->missing($assignment));
    }

    private function assignment(AssignmentNature $nature, bool $withProgram): Assignment
    {
        $assignment = (new \ReflectionClass(Assignment::class))->newInstanceWithoutConstructor();
        $assignment->setNature($nature);
        $assignment->setProgram($withProgram ? (new \ReflectionClass(Program::class))->newInstanceWithoutConstructor() : null);

        return $assignment;
    }
}

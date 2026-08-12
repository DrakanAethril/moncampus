<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assignment;
use App\Entity\AssignmentExpectedProduction;
use App\Entity\AudioRecording;
use App\Entity\Evaluation;
use App\Entity\QuizInstance;
use App\Entity\VideoResource;
use App\Enum\AssignmentNature;
use App\Enum\SelfAssessmentFeedback;
use App\Service\AssignmentNatureFields;
use PHPUnit\Framework\TestCase;

/**
 * What survives when a travail à faire changes nature.
 *
 * Every nature only carries part of step 3's fields, but the ones it does not carry stay in the DOM
 * and come back with the form. Without this rule a quiz picked and then abandoned would follow a
 * travail that became a reading - the student would open a reading that expects a quiz attempt
 * nobody can make.
 *
 * None of it is visible from the screen, which shows the fields of the nature currently selected
 * and says nothing about what it just discarded.
 */
class AssignmentNatureFieldsTest extends TestCase
{
    private AssignmentNatureFields $fields;

    protected function setUp(): void
    {
        $this->fields = new AssignmentNatureFields();
    }

    public function testAbandoningAQuizDropsItAndItsTarget(): void
    {
        $assignment = $this->assignment(AssignmentNature::ToRead);
        $assignment->setQuizInstance($this->entity(QuizInstance::class));
        $assignment->setMinimumScorePercent(70);

        $this->fields->apply($assignment);

        self::assertNull($assignment->getQuizInstance());
        self::assertNull($assignment->getMinimumScorePercent(), 'the target only ever qualifies a quiz');
    }

    public function testAQuizKeepsItsQuizAndTarget(): void
    {
        $assignment = $this->assignment(AssignmentNature::Quiz);
        $quiz = $this->entity(QuizInstance::class);
        $assignment->setQuizInstance($quiz);
        $assignment->setMinimumScorePercent(70);

        $this->fields->apply($assignment);

        self::assertSame($quiz, $assignment->getQuizInstance());
        self::assertSame(70.0, $assignment->getMinimumScorePercent());
    }

    public function testAbandoningAListeningDropsTheRecording(): void
    {
        $assignment = $this->assignment(AssignmentNature::Exercices);
        $assignment->setAudioRecording($this->entity(AudioRecording::class));

        $this->fields->apply($assignment);

        self::assertNull($assignment->getAudioRecording());
    }

    public function testAbandoningAWatchingDropsTheVideo(): void
    {
        $assignment = $this->assignment(AssignmentNature::Exercices);
        $assignment->setVideoResource($this->entity(VideoResource::class));

        $this->fields->apply($assignment);

        self::assertNull($assignment->getVideoResource());
    }

    public function testAWatchingKeepsItsVideo(): void
    {
        $assignment = $this->assignment(AssignmentNature::Watching);
        $video = $this->entity(VideoResource::class);
        $assignment->setVideoResource($video);

        $this->fields->apply($assignment);

        self::assertSame($video, $assignment->getVideoResource());
    }

    public function testAbandoningASelfAssessmentDropsItsEvaluationAndFeedback(): void
    {
        $assignment = $this->assignment(AssignmentNature::Exercices);
        $assignment->setEvaluation($this->entity(Evaluation::class));
        $assignment->setSelfAssessmentFeedback(SelfAssessmentFeedback::Comparison);

        $this->fields->apply($assignment);

        self::assertNull($assignment->getEvaluation());
        self::assertNull($assignment->getSelfAssessmentFeedback());
    }

    public function testASelfAssessmentAlwaysEndsUpWithAFeedbackMode(): void
    {
        // The mockup offers a single one and therefore never asks the question.
        $assignment = $this->assignment(AssignmentNature::SelfAssessment);

        $this->fields->apply($assignment);

        self::assertSame(SelfAssessmentFeedback::Comparison, $assignment->getSelfAssessmentFeedback());
    }

    public function testAWorkWithNoSubmissionKeepsNoExpectedProduction(): void
    {
        $assignment = $this->assignment(AssignmentNature::ToRead);
        $assignment->setLateSubmissionAllowed(true);
        $assignment->addExpectedProduction($this->production('Compte rendu'));

        $this->fields->apply($assignment);

        self::assertCount(0, $assignment->getExpectedProductions());
        self::assertFalse($assignment->isLateSubmissionAllowed());
    }

    public function testASubmissionKeepsItsExpectedProductions(): void
    {
        $assignment = $this->assignment(AssignmentNature::ToSubmit);
        $assignment->addExpectedProduction($this->production('Compte rendu'));

        $this->fields->apply($assignment);

        self::assertCount(1, $assignment->getExpectedProductions());
    }

    public function testAnUnnamedProductionIsAbandonedAndTheRestRenumbered(): void
    {
        // An empty row announces nothing, and the positions must close back up - otherwise the
        // remaining productions keep a gap the student's board would order around.
        $assignment = $this->assignment(AssignmentNature::ToSubmit);
        $assignment->addExpectedProduction($this->production('Rapport'));
        $assignment->addExpectedProduction($this->production('   '));
        $assignment->addExpectedProduction($this->production('Annexes'));

        $this->fields->apply($assignment);

        $kept = $assignment->getExpectedProductions()->toArray();
        self::assertCount(2, $kept);
        self::assertSame(['Rapport', 'Annexes'], array_values(array_map(
            static fn (AssignmentExpectedProduction $p): string => $p->getName(),
            $kept,
        )));
        self::assertSame([0, 1], array_values(array_map(
            static fn (AssignmentExpectedProduction $p): int => $p->getPosition(),
            $kept,
        )));
    }

    public function testReadTrackingOnlyMakesSenseOnAReading(): void
    {
        $assignment = $this->assignment(AssignmentNature::Exercices);
        $assignment->setReadTrackingEnabled(true);

        $this->fields->apply($assignment);

        self::assertFalse($assignment->isReadTrackingEnabled());

        $reading = $this->assignment(AssignmentNature::ToRead);
        $reading->setReadTrackingEnabled(true);
        $this->fields->apply($reading);

        self::assertTrue($reading->isReadTrackingEnabled());
    }

    public function testAnUngradedWorkCannotShowAGradeToStudents(): void
    {
        $assignment = $this->assignment(AssignmentNature::Exercices);
        $assignment->setGraded(false);
        $assignment->setGradingVisibleToStudents(true);

        $this->fields->apply($assignment);

        self::assertFalse($assignment->isGradingVisibleToStudents());
    }

    private function assignment(AssignmentNature $nature): Assignment
    {
        $assignment = (new \ReflectionClass(Assignment::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($assignment, 'options'))->setValue($assignment, new \Doctrine\Common\Collections\ArrayCollection());
        (new \ReflectionProperty($assignment, 'expectedProductions'))->setValue($assignment, new \Doctrine\Common\Collections\ArrayCollection());
        $assignment->setNature($nature);

        return $assignment;
    }

    private function production(string $name): AssignmentExpectedProduction
    {
        return (new AssignmentExpectedProduction())->setName($name);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function entity(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}

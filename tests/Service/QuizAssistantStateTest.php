<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\QuizAssistantPath;
use App\Service\QuizAssistantRequest;
use App\Service\QuizAssistantState;
use PHPUnit\Framework\TestCase;

/**
 * What the assistant remembers between its steps.
 *
 * It lives in the session because step 2 ends *outside* the application - the teacher leaves with a
 * prompt and comes back with a document, sometimes a quarter of an hour later. Coming back must
 * resume, so this object has to survive a round trip through session storage and read back whatever
 * it finds there, including nothing at all.
 */
class QuizAssistantStateTest extends TestCase
{
    public function testAFreshStateHasNoPath(): void
    {
        $state = QuizAssistantState::fromArray([]);

        self::assertNull($state->path);
        self::assertNull($state->sequenceId);
        self::assertNull($state->seanceId);
        self::assertFalse($state->liveOnly);
        self::assertTrue($state->request->isEmpty());
    }

    public function testItSurvivesARoundTripThroughTheSession(): void
    {
        $state = new QuizAssistantState(
            path: QuizAssistantPath::Course,
            seanceId: 42,
            liveOnly: true,
            request: new QuizAssistantRequest(questionCount: 15),
        );

        self::assertEquals($state, QuizAssistantState::fromArray($state->toArray()));
    }

    public function testAnUnknownPathReadsAsNoPath(): void
    {
        self::assertNull(QuizAssistantState::fromArray(['path' => 'teleport'])->path);
        self::assertNull(QuizAssistantState::fromArray(['path' => ['course']])->path);
    }

    // A séance wins over a séquence: naming both is what the "Quiz" menu of a séance does when the
    // link also carries its parent, and the narrower scope is the one the teacher clicked.
    public function testASeanceWinsOverASequence(): void
    {
        $state = QuizAssistantState::fromArray(['path' => 'course', 'sequenceId' => 7, 'seanceId' => 42]);

        self::assertSame(42, $state->seanceId);
        self::assertNull($state->sequenceId);
    }

    public function testAnIdThatIsNotAPositiveIntegerIsDropped(): void
    {
        foreach (['0', '-3', 'douze', '', null, []] as $raw) {
            $state = QuizAssistantState::fromArray(['path' => 'course', 'sequenceId' => $raw]);
            self::assertNull($state->sequenceId, sprintf('id %s', var_export($raw, true)));
        }
    }

    /**
     * The course path without a course is the case the screen must never reach: it would build a
     * prompt announcing a lesson and carrying none. Reading it back as "no path" sends the teacher
     * to step 1, which is where the missing answer is.
     */
    public function testTheCoursePathWithoutACourseIsNotAPath(): void
    {
        self::assertNull(QuizAssistantState::fromArray(['path' => 'course'])->path);
    }

    public function testTheOtherPathsNeedNoCourse(): void
    {
        self::assertSame(QuizAssistantPath::Transpose, QuizAssistantState::fromArray(['path' => 'transpose'])->path);
        self::assertSame(QuizAssistantPath::Subject, QuizAssistantState::fromArray(['path' => 'subject'])->path);
    }

    public function testItKnowsWhetherTheCourseBlockApplies(): void
    {
        self::assertTrue((new QuizAssistantState(path: QuizAssistantPath::Course, seanceId: 42))->isFromCourse());
        self::assertFalse((new QuizAssistantState(path: QuizAssistantPath::Subject))->isFromCourse());
        self::assertFalse((new QuizAssistantState())->isFromCourse());
    }

    // What the step-2/step-3 links have to carry to keep the scope across a redirect the browser
    // follows - the same shape App\Controller\QuizImportController already puts in the session so the
    // preview can offer « rattacher à la séance … ».
    public function testItExposesTheScopeAsRouteParameters(): void
    {
        self::assertSame(['seance' => 42], (new QuizAssistantState(path: QuizAssistantPath::Course, seanceId: 42))->scopeParams());
        self::assertSame(['sequence' => 7], (new QuizAssistantState(path: QuizAssistantPath::Course, sequenceId: 7))->scopeParams());
        self::assertSame([], (new QuizAssistantState(path: QuizAssistantPath::Subject))->scopeParams());
    }
}

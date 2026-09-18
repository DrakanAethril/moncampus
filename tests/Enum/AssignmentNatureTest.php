<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\AssignmentNature;
use PHPUnit\Framework\TestCase;

/**
 * What each nature of travail à faire claims about itself.
 *
 * The enum is small but it is read from everywhere - the wizard, the student board, the dashboards,
 * the mobile API - and adding a case is exactly the moment one of its match() arms gets forgotten.
 * A forgotten arm is an UnhandledMatchError on a screen, not a type error.
 */
class AssignmentNatureTest extends TestCase
{
    public function testEveryNatureSaysHowToLabelBadgeAndExplainItself(): void
    {
        foreach (AssignmentNature::cases() as $nature) {
            self::assertNotSame('', $nature->labelKey(), $nature->value);
            self::assertNotSame('', $nature->hintKey(), $nature->value);
            self::assertNotSame('', $nature->badgeClass(), $nature->value);
        }
    }

    /**
     * Listening can only be born of an audio recording, which opens the wizard with the nature
     * already set: a card on the grid would offer a nature with nothing to attach to it.
     */
    public function testListeningIsNeverOfferedOnTheGrid(): void
    {
        self::assertNotContains(AssignmentNature::Listening, AssignmentNature::forLessonLog());
    }

    /**
     * Watching is on the grid since videos live in the file library: picking the card asks for one
     * of the teacher's library videos, so there is always something to attach.
     */
    public function testWatchingIsOfferedOnTheGrid(): void
    {
        self::assertContains(AssignmentNature::Watching, AssignmentNature::forLessonLog());
    }

    /**
     * The watch tracking says exactly what the student saw, so there is nothing to declare - the
     * same reason a listening, a quiz and a self-assessment are excluded from the declaration.
     */
    public function testWatchingCarriesItsOwnProofOfCompletion(): void
    {
        $watching = AssignmentNature::Watching;

        self::assertTrue($watching->expectsWatching());
        self::assertFalse($watching->expectsSelfDeclaration());
        self::assertFalse($watching->expectsSubmission());
        self::assertFalse($watching->expectsSelfAssessment());
        self::assertFalse($watching->expectsListening(), 'watching and listening are two natures, not one');
    }

    public function testNoOtherNatureClaimsToBeAWatching(): void
    {
        foreach (AssignmentNature::cases() as $nature) {
            if (AssignmentNature::Watching !== $nature) {
                self::assertFalse($nature->expectsWatching(), $nature->value);
            }
        }
    }

    /**
     * The single most expensive trap of design/validated/surveys.md (§7.7): without this exclusion
     * the student gets a « Marquer comme fait » button that closes the survey without answering it,
     * and the response rate - the one number a survey exists for - lies for good.
     *
     * One line of production code, and this is the test that protects it.
     */
    public function testASurveyIsNeverSettledByADeclaration(): void
    {
        $survey = AssignmentNature::Survey;

        self::assertTrue($survey->expectsSurvey());
        self::assertFalse($survey->expectsSelfDeclaration(), 'the proof of a survey is the response, never a declaration');
        self::assertFalse($survey->expectsSubmission());
        self::assertFalse($survey->expectsSelfAssessment());
        self::assertFalse($survey->expectsListening());
        self::assertFalse($survey->expectsWatching());
    }

    public function testNoOtherNatureClaimsToBeASurvey(): void
    {
        foreach (AssignmentNature::cases() as $nature) {
            if (AssignmentNature::Survey !== $nature) {
                self::assertFalse($nature->expectsSurvey(), $nature->value);
            }
        }
    }

    /**
     * A survey campaign is launched from Outils > Sondages, which opens the wizard with the nature
     * already set - same shape as Listening/Watching, and the same reason to keep it off the grid.
     */
    public function testSurveyIsNeverOfferedOnTheGrid(): void
    {
        self::assertNotContains(AssignmentNature::Survey, AssignmentNature::forLessonLog());
    }
}

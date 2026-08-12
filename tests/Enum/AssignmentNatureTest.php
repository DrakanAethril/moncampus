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
     * Watching is the twin of Listening: a video assignment can only be born of a video resource,
     * from the "Vidéos" tool which opens the wizard with the nature already set. Neither is a card
     * on the grid of types, which would offer a nature with nothing to attach to it.
     */
    public function testTheTwoMediaNaturesAreNeverOfferedOnTheGrid(): void
    {
        $offered = AssignmentNature::forLessonLog();

        self::assertNotContains(AssignmentNature::Watching, $offered);
        self::assertNotContains(AssignmentNature::Listening, $offered);
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
}

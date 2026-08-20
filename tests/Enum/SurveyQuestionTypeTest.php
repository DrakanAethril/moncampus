<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SurveyQuestionType;
use PHPUnit\Framework\TestCase;

/**
 * The three classifying methods of the survey question type - three lines of production code, and
 * what holds the five separate counts of design/validated/surveys.md §7.13.
 *
 * Titre is the case worth pinning: it is a line in the ordering and nothing else, so it must be
 * out of every count, out of every comparison, and never carry an answer row. Commentaire is the
 * second: it *is* answered, but it has no proposed answers and never enters a comparison - two
 * lists of verbatims put side by side do not subtract.
 */
class SurveyQuestionTypeTest extends TestCase
{
    public function testOnlyTheThreeChoiceTypesCarryProposedAnswers(): void
    {
        self::assertTrue(SurveyQuestionType::Unique->hasAnswers());
        self::assertTrue(SurveyQuestionType::Multiple->hasAnswers());
        self::assertTrue(SurveyQuestionType::Ordre->hasAnswers());

        self::assertFalse(SurveyQuestionType::Commentaire->hasAnswers(), 'a free text has nothing to propose');
        self::assertFalse(SurveyQuestionType::Titre->hasAnswers(), 'an intertitle is not a question');
    }

    public function testTitreIsTheOnlyTypeThatIsNotAnswered(): void
    {
        self::assertFalse(SurveyQuestionType::Titre->isAnswerable());

        foreach (SurveyQuestionType::cases() as $type) {
            if (SurveyQuestionType::Titre !== $type) {
                self::assertTrue($type->isAnswerable(), $type->value);
            }
        }
    }

    /**
     * Comparability follows "has proposed answers" exactly: a verbatim is displayed wave by wave
     * and never aligned, and an intertitle has nothing to align at all.
     */
    public function testOnlyTheTypesWithAnswersEnterTheWaveComparison(): void
    {
        foreach (SurveyQuestionType::cases() as $type) {
            self::assertSame($type->hasAnswers(), $type->isComparable(), $type->value);
        }

        self::assertFalse(SurveyQuestionType::Commentaire->isComparable());
        self::assertFalse(SurveyQuestionType::Titre->isComparable());
    }

    /**
     * There is no "Échelle" type: an ordered scale is a Unique question carrying the flag, which
     * declares that the answers' order_index *is* a value (§12.A).
     */
    public function testTheScaleFlagOnlyMeansSomethingOnASingleChoice(): void
    {
        self::assertTrue(SurveyQuestionType::Unique->supportsScale());

        foreach (SurveyQuestionType::cases() as $type) {
            if (SurveyQuestionType::Unique !== $type) {
                self::assertFalse($type->supportsScale(), $type->value);
            }
        }
    }

    public function testChoiceBoundsOnlyMeanSomethingOnAMultipleChoice(): void
    {
        self::assertTrue(SurveyQuestionType::Multiple->supportsChoiceBounds());

        foreach (SurveyQuestionType::cases() as $type) {
            if (SurveyQuestionType::Multiple !== $type) {
                self::assertFalse($type->supportsChoiceBounds(), $type->value);
            }
        }
    }

    public function testEveryTypeSaysHowToLabelAndExplainItself(): void
    {
        foreach (SurveyQuestionType::cases() as $type) {
            self::assertNotSame('', $type->labelKey(), $type->value);
            self::assertNotSame('', $type->hintKey(), $type->value);
        }
    }

    public function testTheEditorOffersTheFiveTypes(): void
    {
        self::assertSame(SurveyQuestionType::cases(), SurveyQuestionType::forEditor());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Survey;

use App\Enum\SurveyQuestionType;
use App\Service\Survey\SurveyAssistantRequest;
use App\Service\Survey\SurveyPromptCatalog;
use PHPUnit\Framework\TestCase;

/**
 * « Ma demande », the one block of the prompt that is written twice - once here, once in
 * assets/controllers/survey_prompt_builder_controller.js as the author types.
 *
 * What these tests pin is what makes that duplication safe: PHP owns the *shape* (which lines exist,
 * in which order, what a blank field falls back to) and hands it over as a %token% template, so the
 * browser only ever substitutes values. The day the two disagree, the prompt on screen and the one
 * this class renders differ by a line nobody notices.
 */
class SurveyPromptCatalogTest extends TestCase
{
    /** A blank field keeps its bracketed example, so filling nothing in still hands over an instruction. */
    public function testABlankRequestKeepsEveryBracketedExample(): void
    {
        $demand = SurveyPromptCatalog::demand(new SurveyAssistantRequest());

        foreach (SurveyPromptCatalog::demandPlaceholders() as $placeholder) {
            self::assertStringContainsString($placeholder, $demand);
        }
        self::assertStringNotContainsString('%', $demand);
    }

    public function testAFilledRequestReplacesTheExamples(): void
    {
        $demand = SurveyPromptCatalog::demand(new SurveyAssistantRequest(
            theme: 'Vie scolaire',
            goal: 'ce qui se passe entre deux cours',
            audience: 'BTS SIO 1',
            questionCount: 8,
        ));

        self::assertStringContainsString('Sujet du sondage : Vie scolaire', $demand);
        self::assertStringContainsString('Ce que je veux savoir : ce qui se passe entre deux cours', $demand);
        self::assertStringContainsString('Public interrogé : BTS SIO 1', $demand);
        self::assertStringContainsString('Nombre de questions : 8', $demand);
    }

    /** A heading followed by nothing reads, to a model, as an instruction that went missing. */
    public function testTheExtraHeadingTravelsWithItsValueAndOnlyThen(): void
    {
        self::assertStringNotContainsString(
            SurveyPromptCatalog::EXTRA_HEADING,
            SurveyPromptCatalog::demand(new SurveyAssistantRequest(theme: 'Vie scolaire')),
        );

        $withExtra = SurveyPromptCatalog::demand(new SurveyAssistantRequest(extra: 'Une partie sur l\'alternance.'));
        self::assertStringContainsString(SurveyPromptCatalog::EXTRA_HEADING."\nUne partie sur l'alternance.", $withExtra);
    }

    /** The template and the values are the two halves the browser is handed: they must fit each other. */
    public function testTheBrowserTemplateHasOneHolePerValue(): void
    {
        $template = SurveyPromptCatalog::demandTemplate();

        foreach (array_keys(SurveyPromptCatalog::demandValues(new SurveyAssistantRequest())) as $token) {
            self::assertStringContainsString($token, $template);
        }
    }

    /** Every type of the editor is offered a fragment: a type without one is silently absent from the prompt. */
    public function testEveryQuestionTypeCarriesItsFragment(): void
    {
        foreach (SurveyQuestionType::forEditor() as $type) {
            self::assertNotSame('', trim(SurveyPromptCatalog::fragmentFor($type)));
        }

        self::assertCount(\count(SurveyQuestionType::cases()), SurveyPromptCatalog::fragments());
    }
}

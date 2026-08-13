<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuizAssistantRequest;
use App\Service\QuizPromptCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The « Ma demande » block, once the assistant fills it instead of the teacher.
 *
 * The rule that carries the whole design: **a blank field keeps its bracketed example**. That makes
 * the all-blank case byte-for-byte the prompt the one-page screen used to hand over, so moving the
 * fields into the application can only improve the copied text, never degrade it.
 */
class QuizPromptDemandTest extends TestCase
{
    public function testAnEmptyRequestKeepsTheBracketedSkeleton(): void
    {
        $demand = QuizPromptCatalog::demand(new QuizAssistantRequest(), fromCourse: false);

        self::assertStringContainsString('Matière : [Réseaux]', $demand);
        self::assertStringContainsString('Notions travaillées : [VLAN, trunk, 802.1Q]', $demand);
        self::assertStringContainsString('Public : [BTS SIO 2e année, SISR]', $demand);
        self::assertStringContainsString('Nombre de questions : [15]', $demand);
    }

    public function testAFilledFieldReplacesItsExample(): void
    {
        $demand = QuizPromptCatalog::demand(new QuizAssistantRequest(
            subjectMatter: 'Cybersécurité',
            notions: 'Chiffrement asymétrique, PKI',
            audience: 'BTS SIO 1re année',
            questionCount: 12,
        ), fromCourse: false);

        self::assertStringContainsString('Matière : Cybersécurité', $demand);
        self::assertStringContainsString('Notions travaillées : Chiffrement asymétrique, PKI', $demand);
        self::assertStringContainsString('Public : BTS SIO 1re année', $demand);
        self::assertStringContainsString('Nombre de questions : 12', $demand);
        self::assertStringNotContainsString('[Réseaux]', $demand);
        self::assertStringNotContainsString('[15]', $demand);
    }

    public function testFieldsAreFilledIndependently(): void
    {
        $demand = QuizPromptCatalog::demand(new QuizAssistantRequest(subjectMatter: 'Réseaux'), fromCourse: false);

        self::assertStringContainsString('Matière : Réseaux', $demand);
        self::assertStringContainsString('Notions travaillées : [VLAN, trunk, 802.1Q]', $demand);
    }

    /**
     * From a course, the subject/notions/audience are already stated by the course block itself
     * (App\Service\QuizSourceContext) - repeating them invites the model to arbitrate between two
     * descriptions of the same lesson. The count survives, because it is the one thing no course can
     * answer, and the teacher asked for it on every path.
     */
    public function testFromACourseOnlyTheCountAndTheExtraRemain(): void
    {
        $demand = QuizPromptCatalog::demand(new QuizAssistantRequest(questionCount: 20), fromCourse: true);

        self::assertStringContainsString('Nombre de questions : 20', $demand);
        self::assertStringNotContainsString('Matière', $demand);
        self::assertStringNotContainsString('Notions travaillées', $demand);
        self::assertStringNotContainsString('Public', $demand);
    }

    public function testFromACourseABlankCountStillShowsItsExample(): void
    {
        $demand = QuizPromptCatalog::demand(new QuizAssistantRequest(), fromCourse: true);

        self::assertStringContainsString('Nombre de questions : [15]', $demand);
    }

    public function testTheExtraIsAppendedUnderItsOwnHeading(): void
    {
        $demand = QuizPromptCatalog::demand(new QuizAssistantRequest(
            extra: "Deux questions de calcul de masque.\nPas de question sur les RFC.",
        ), fromCourse: false);

        self::assertStringContainsString('Deux questions de calcul de masque.', $demand);
        self::assertStringContainsString('Pas de question sur les RFC.', $demand);
        // Below the fields, never above: the précisions qualify the request, they do not replace it.
        self::assertGreaterThan(mb_strpos($demand, 'Nombre de questions'), (int) mb_strpos($demand, 'Deux questions de calcul'));
    }

    public function testNoExtraHeadingWhenNothingWasTyped(): void
    {
        $demand = QuizPromptCatalog::demand(new QuizAssistantRequest(), fromCourse: false);

        self::assertStringNotContainsString(QuizPromptCatalog::EXTRA_HEADING, $demand);
    }

    // The block is glued into a prompt built by concatenation - a stray blank line at either end
    // shows up as a hole in the copied text.
    public function testTheBlockIsTrimmed(): void
    {
        $demand = QuizPromptCatalog::demand(new QuizAssistantRequest(extra: 'Rien de plus.'), fromCourse: false);

        self::assertSame(trim($demand), $demand);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\QuizSourceScope;
use App\Service\QuizPromptCatalog;
use App\Service\QuizSourceContext;
use PHPUnit\Framework\TestCase;

/**
 * The course a quiz is being generated from, written into the prompt that carries it.
 *
 * The twin of App\Service\VideoImportContext in role: a language model cannot read the séquence, so
 * the prompt the teacher carries has to contain it. Before this, generation was blind to the course -
 * the teacher retyped "BTS SIO 2, SISR, Ansible, VLAN…" into the prompt's bracketed fields and got
 * questions about the subject rather than about their own lesson.
 *
 * Two things it must get right, and both are about not lying:
 *
 * - **It counts characters of the text that is actually sent**, so the figure on screen is the figure
 *   the model will receive.
 * - **It never truncates.** Over the cap it says so and names the two levers (drop the phases, narrow
 *   the scope to one séance); cutting the course in half silently would produce questions about the
 *   first half and no way to know why.
 *
 * Built on primitives so both are testable without a database, exactly as
 * App\Service\AccessConditionEvaluator is - App\Service\QuizSourceContextFactory is the part that
 * reads entities.
 */
class QuizSourceContextTest extends TestCase
{
    public function testItNamesTheSequenceItComesFrom(): void
    {
        $text = $this->sequenceContext()->text(withObjectives: true, withPhases: false);

        self::assertStringContainsString(QuizPromptCatalog::CONTEXT_HEADING, $text);
        self::assertStringContainsString('Automatisation avec Ansible', $text);
        self::assertStringContainsString('séquence', $text);
    }

    public function testASeanceSaysSeanceRatherThanSequence(): void
    {
        $text = $this->seanceContext()->text(withObjectives: true, withPhases: true);

        self::assertStringContainsString('séance', $text);
        self::assertStringNotContainsString('la séquence «', $text);
    }

    /** The two boxes are independent, and each one only adds its own part. */
    public function testEachBoxAddsOnlyItsOwnPart(): void
    {
        $context = $this->sequenceContext();

        $objectivesOnly = $context->text(withObjectives: true, withPhases: false);
        self::assertStringContainsString('principe agentless', $objectivesOnly);
        self::assertStringNotContainsString('Situation déclenchante', $objectivesOnly);

        $phasesOnly = $context->text(withObjectives: false, withPhases: true);
        self::assertStringNotContainsString('principe agentless', $phasesOnly);
        self::assertStringContainsString('Situation déclenchante', $phasesOnly);

        $both = $context->text(withObjectives: true, withPhases: true);
        self::assertStringContainsString('principe agentless', $both);
        self::assertStringContainsString('Situation déclenchante', $both);
    }

    /** Nothing ticked is a legitimate answer, and must not send a heading with a hole under it. */
    public function testNothingTickedProducesNoBlockAtAll(): void
    {
        self::assertSame('', $this->sequenceContext()->text(withObjectives: false, withPhases: false));
    }

    /** A séquence whose fields are all empty offers nothing rather than an empty ceremony. */
    public function testACourseWithNothingWrittenDownHasNoContextToOffer(): void
    {
        $empty = new QuizSourceContext(QuizSourceScope::Sequence, 'Séquence vide', '', '');

        self::assertFalse($empty->hasObjectives());
        self::assertFalse($empty->hasPhases());
        self::assertSame('', $empty->text(withObjectives: true, withPhases: true));
    }

    /**
     * The counter counts what is sent, so the number on screen is the number the model receives -
     * headings included, because they are part of the payload too.
     */
    public function testTheLengthIsTheLengthOfWhatIsActuallySent(): void
    {
        $context = $this->sequenceContext();

        self::assertSame(
            mb_strlen($context->text(withObjectives: true, withPhases: true)),
            $context->length(withObjectives: true, withPhases: true),
        );
        self::assertSame(0, $context->length(withObjectives: false, withPhases: false));
        self::assertGreaterThan(
            $context->length(withObjectives: true, withPhases: false),
            $context->length(withObjectives: true, withPhases: true),
        );
    }

    /** Accented text is counted in characters, not bytes - "é" is not two of anything a reader sees. */
    public function testCharactersAreCountedRatherThanBytes(): void
    {
        $context = new QuizSourceContext(QuizSourceScope::Seance, 'T', 'ééé', '');

        self::assertSame(
            mb_strlen($context->text(withObjectives: true, withPhases: false)),
            $context->length(withObjectives: true, withPhases: false),
        );
        self::assertLessThan(
            \strlen($context->text(withObjectives: true, withPhases: false)),
            $context->length(withObjectives: true, withPhases: false),
        );
    }

    public function testGoingOverTheCapIsReportedAndNothingIsCut(): void
    {
        $long = str_repeat('a', QuizPromptCatalog::MAX_CONTEXT_CHARACTERS + 1);
        $context = new QuizSourceContext(QuizSourceScope::Sequence, 'Longue', $long, '');

        self::assertTrue($context->exceedsCap(withObjectives: true, withPhases: false));
        // Nothing is cut: the whole text is still there, and the screen is what says so.
        self::assertStringContainsString($long, $context->text(withObjectives: true, withPhases: false));
        self::assertFalse($context->exceedsCap(withObjectives: false, withPhases: false));
    }

    public function testUnderTheCapNothingIsReported(): void
    {
        self::assertFalse($this->sequenceContext()->exceedsCap(withObjectives: true, withPhases: true));
    }

    /**
     * The two parts are readable on their own because the browser assembles the prompt as the teacher
     * ticks (quiz_prompt_builder_controller.js) and has to count without a round trip.
     */
    public function testBothPartsAreReadableOnTheirOwnForTheBrowserToAssemble(): void
    {
        $context = $this->sequenceContext();

        self::assertStringContainsString('principe agentless', $context->objectives());
        self::assertStringContainsString('Situation déclenchante', $context->phases());
    }

    private function sequenceContext(): QuizSourceContext
    {
        return new QuizSourceContext(
            QuizSourceScope::Sequence,
            'Automatisation avec Ansible',
            'Expliquer le principe agentless et le rôle du nœud de contrôle.',
            "Séance 1 — Prendre la main sur un parc (4 h)\n- Accueil et problématisation (20 min) : Situation déclenchante projetée.",
        );
    }

    private function seanceContext(): QuizSourceContext
    {
        return new QuizSourceContext(
            QuizSourceScope::Seance,
            'Prendre la main sur un parc',
            'Écrire un inventaire statique.',
            '- Atelier guidé (1 h 15) : inventaire à la main.',
        );
    }
}

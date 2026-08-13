<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\QuestionType;
use App\Service\QuizPromptCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The pieces the quiz import screen's prompt is built from.
 *
 * They used to be twelve `{% set %}` captures inside quiz_import_interactive.html.twig. Moving them
 * here changes nothing about the text - assets/controllers/quiz_prompt_builder_controller.js still
 * assembles it in the browser, and still trims every block - but it puts the twelve in one place
 * before a third prompt screen arrives (the séquence import assistant), which is the point at which
 * inline fragments start to diverge.
 *
 * They stay in French and are never translated: this is the text sent to the model, not a message
 * about it. Translating a fragment would change what comes back.
 *
 * The test worth having is not a copy of the strings - it is that the map covers the enum exactly.
 * A thirteenth question type whose fragment nobody wrote would otherwise show up as a type the
 * teacher can tick and the model is never told about.
 */
class QuizPromptCatalogTest extends TestCase
{
    public function testEveryQuestionTypeHasAFragmentAndNoneIsOrphaned(): void
    {
        $fragments = QuizPromptCatalog::fragments();

        self::assertSame(
            array_map(static fn (QuestionType $case): string => $case->value, QuestionType::cases()),
            array_keys($fragments),
            'the fragments must cover QuestionType exactly, in the enum\'s own order',
        );
    }

    public function testEachFragmentNamesTheTypeItDescribes(): void
    {
        foreach (QuizPromptCatalog::fragments() as $type => $fragment) {
            self::assertStringStartsWith(\sprintf('- "%s" :', $type), $fragment);
            self::assertSame(trim($fragment), $fragment, 'the browser trims each block; a stored blank would be a silent no-op');
        }
    }

    public function testTheEnvelopeAndClosingFrameTheDocument(): void
    {
        // The envelope announces the format the importers actually read (MixedJsonImporter), and the
        // closing carries the "vary the types" instruction the mixed reader exists to honour.
        self::assertStringContainsString('moncampus-quiz/1', QuizPromptCatalog::envelope());
        self::assertStringContainsString('jamais plus de deux questions consécutives du même type', QuizPromptCatalog::closing());
        self::assertSame(trim(QuizPromptCatalog::envelope()), QuizPromptCatalog::envelope());
        self::assertSame(trim(QuizPromptCatalog::closing()), QuizPromptCatalog::closing());
    }

    public function testAFragmentIsFoundByItsEnumCase(): void
    {
        self::assertSame(QuizPromptCatalog::fragments()['calculee'], QuizPromptCatalog::fragmentFor(QuestionType::Calculee));
    }

    /**
     * The transposition closing - "I already have my QCM, put it in the format" - which is the same
     * first question the séquence assistant asks, and the same answer: the application only converts.
     *
     * It replaces "Ma demande" with "Mon support", and that is the whole difference in structure. The
     * difference in *risk* is the opposite of generation's: a conversion does not produce generic
     * questions, it quietly completes the ones it could not read.
     */
    public function testTheTransposeClosingAsksForADocumentRatherThanASubject(): void
    {
        $closing = QuizPromptCatalog::transposeClosing();

        self::assertStringContainsString('Mon support', $closing);
        self::assertStringNotContainsString('Ma demande', $closing);
        self::assertStringNotContainsString('Nombre de questions', $closing, 'a transposition produces as many questions as the document holds');
        self::assertSame(trim($closing), $closing);
    }

    /**
     * The trap the Ansible kit pays for: its `06-evaluation/qcm-final.md` holds twenty written
     * questions and **no answers** - those live in `qcm-final-corrige.md`, a second file. A conversion
     * given only the first produces twenty plausible, importable, uncorrected questions, and nothing
     * downstream can tell them from twenty right ones.
     *
     * So the prompt refuses instead of guessing, and declares what it refused. The preview counts the
     * questions without a corrigé on its own side (App\Service\QuizQuestionCompleteness); this is the
     * half that stops them being invented in the first place.
     */
    public function testTheTransposeClosingRefusesAQuestionWhoseAnswerWasNotProvided(): void
    {
        $closing = QuizPromptCatalog::transposeClosing();

        self::assertStringContainsString('corrigé', $closing);
        self::assertStringContainsString('N\'INVENTE JAMAIS', $closing);
        self::assertStringContainsString('écartées', $closing);
    }

    /** The context block's own words live here, with the rest of the prompt's text. */
    public function testTheContextBlockNamesTheCourseTheQuestionsMustBeAbout(): void
    {
        self::assertStringStartsWith('#', QuizPromptCatalog::CONTEXT_HEADING);
        self::assertStringContainsString('%title%', QuizPromptCatalog::CONTEXT_SEQUENCE_TEMPLATE);
        self::assertStringContainsString('%title%', QuizPromptCatalog::CONTEXT_SEANCE_TEMPLATE);
        self::assertStringContainsString('séquence', QuizPromptCatalog::CONTEXT_SEQUENCE_TEMPLATE);
        self::assertStringContainsString('séance', QuizPromptCatalog::CONTEXT_SEANCE_TEMPLATE);
    }

    /**
     * A cap the application can state, not one it can know: the number of characters a model accepts
     * is a property of the model. It is deliberately generous enough that a single séance always fits
     * and honest enough that a whole séquence's phases do not (the Ansible kit's 26 phases go well
     * past it) - which is the case the warning exists for.
     */
    public function testTheContextCapIsAStatedNumberAndNotZero(): void
    {
        self::assertGreaterThan(1000, QuizPromptCatalog::MAX_CONTEXT_CHARACTERS);
    }
}

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
}

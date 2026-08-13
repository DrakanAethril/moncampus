<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\EvaluationNature;
use App\Service\SequenceJsonImporter;
use App\Service\SequencePromptCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The prompt the assistant hands the teacher to carry to a model.
 *
 * It is the only part of the feature that is not code the application runs, and the part that
 * decides whether the import tells the truth. The "Non placé" panel can only list what the model
 * declared, so the prompt has to make declaring the *cheap* answer: it names the closed list of keys
 * that exist, which makes "this has no field" something the model can work out rather than something
 * it has to be generous enough to volunteer.
 *
 * French and untranslated, like App\Service\QuizPromptCatalog: this is the text sent to the model.
 */
class SequencePromptCatalogTest extends TestCase
{
    public function testTheDocumentItAsksForIsTheOneTheImporterReads(): void
    {
        $prompt = SequencePromptCatalog::prompt();

        self::assertStringContainsString(SequenceJsonImporter::FORMAT, $prompt);
    }

    /**
     * Every key the format has, named in the prompt - and nothing else. A key the prompt forgets is
     * content the model has nowhere to put and will either drop or improvise a home for.
     */
    public function testItNamesEveryFieldTheThreeEntitiesActuallyHave(): void
    {
        $prompt = SequencePromptCatalog::prompt();

        foreach (['capacitesAttendues', 'preRequis', 'transversalites', 'situationProblematique', 'supportsGeneraux'] as $key) {
            self::assertStringContainsString($key, $prompt);
        }
        foreach (['avantDescription', 'apresDescription', 'cahierDeTexteDescription', 'evaluationNature'] as $key) {
            self::assertStringContainsString($key, $prompt);
        }
        foreach (['moyensSupports', 'difficultes', 'enseignant', 'etudiant'] as $key) {
            self::assertStringContainsString($key, $prompt);
        }
    }

    /** The closed list comes from the enum, so a fourth nature could never be invented here. */
    public function testTheEvaluationNaturesAreTheEnumsOwn(): void
    {
        $prompt = SequencePromptCatalog::prompt();

        foreach (EvaluationNature::cases() as $case) {
            self::assertStringContainsString('"'.$case->value.'"', $prompt);
        }
    }

    public function testItDemandsTheReportAndItsThreeLists(): void
    {
        $prompt = SequencePromptCatalog::prompt();

        self::assertStringContainsString('"rapport"', $prompt);
        foreach (['deduit', 'nonPlace', 'vide'] as $list) {
            self::assertStringContainsString($list, $prompt);
        }
        // An unplaced block carries its text, or "verser dans un champ" has nothing to pour.
        self::assertStringContainsString('"contenu"', $prompt);
    }

    /** The DECIMAL(10,2)-of-minutes trap, closed in the prompt rather than only in the parser. */
    public function testItForbidsADurationWithoutItsUnit(): void
    {
        self::assertStringContainsString('"4 h"', SequencePromptCatalog::prompt());
        self::assertStringContainsString('jamais un nombre nu', SequencePromptCatalog::prompt());
    }

    public function testItAsksForRestrictedMarkdownAndNotHtml(): void
    {
        $prompt = SequencePromptCatalog::prompt();

        self::assertStringContainsString('Markdown restreint', $prompt);
        self::assertStringContainsString('tableaux', $prompt);
        self::assertStringContainsString('Jamais de HTML', $prompt);
    }

    /**
     * The teacher's own labels travel with the prompt. Left to invent them, a model writes "BTS SIO
     * 2ème année" next to the "BTS SIO 2" they already use, and the library grows a duplicate tag
     * that nothing will ever merge.
     */
    public function testTheTeachersOwnLabelsAreWrittenIntoIt(): void
    {
        $prompt = SequencePromptCatalog::prompt('BTS SIO 2', 'SISR', ['Bloc 1', 'Bloc 2']);

        self::assertStringContainsString('BTS SIO 2', $prompt);
        self::assertStringContainsString('SISR', $prompt);
        self::assertStringContainsString('Bloc 1', $prompt);
        self::assertStringContainsString(SequencePromptCatalog::LABELS_PLACEHOLDER, SequencePromptCatalog::body());
        self::assertStringNotContainsString(SequencePromptCatalog::LABELS_PLACEHOLDER, $prompt);
    }

    /** Nothing chosen is a legitimate answer, and must not leave a dangling sentence. */
    public function testWithoutAnyLabelItSaysSoRatherThanLeavingAHole(): void
    {
        $prompt = SequencePromptCatalog::prompt();

        self::assertStringNotContainsString('« »', $prompt);
        self::assertStringNotContainsString('  ·', $prompt);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SequenceImportException;
use App\Service\SequenceJsonImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-sequence/1" format: a séquence a teacher already owns, transposed by a language
 * model into something the library can read.
 *
 * The format is the field list of SequenceTemplate/SeanceTemplate/SeancePhaseTemplate and invents
 * nothing - except `rapport`, which is not content but what the conversion knows about itself, and
 * which the review screen turns into its "Non placé" panel. MonCampus is poorer than a real séquence
 * sheet (differentiation, points de vigilance, matériel, livrable have no field at all), so a
 * conversion that says nothing about what it dropped would leave the teacher believing their
 * séquence is in the application. That is why a document without `rapport` is refused outright: its
 * absence means the model ignored the instructions, and nothing else in the document can be trusted
 * to have followed them either.
 *
 * Nothing here writes: parse() answers a payload that lives in the session, exactly like the quiz
 * import (App\Controller\QuizImportController). Entities are built by App\Service\SequenceImportWriter
 * once the teacher has confirmed.
 */
class SequenceJsonImporterTest extends TestCase
{
    private SequenceJsonImporter $importer;

    protected function setUp(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id.($parameters ? ' '.implode('|', array_map(strval(...), $parameters)) : '');
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };

        // The same shape as config/packages/html_sanitizer.yaml's app.library_content.
        $sanitizer = new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements());

        $this->importer = new SequenceJsonImporter($translator, $sanitizer);
    }

    public function testReadsASequenceItsSeancesAndItsPhasesInDocumentOrder(): void
    {
        $payload = $this->importer->parse($this->document([
            'sequence' => ['titre' => 'Automatisation avec Ansible', 'niveau' => 'BTS SIO 2', 'option' => 'SISR', 'blocs' => ['Bloc 1', 'Bloc 2']],
            'seances' => [
                ['titre' => 'Prendre la main sur un parc', 'phases' => [['nom' => 'Accueil'], ['nom' => 'Apport théorique']]],
                ['titre' => 'Playbooks'],
            ],
        ]));

        self::assertSame('Automatisation avec Ansible', $payload['sequence']['titre']);
        self::assertSame('BTS SIO 2', $payload['sequence']['niveau']);
        self::assertSame(['Bloc 1', 'Bloc 2'], $payload['sequence']['blocs']);
        self::assertCount(2, $payload['seances']);
        // No ordering field anywhere in the format: the order of the arrays is the order.
        self::assertSame('Prendre la main sur un parc', $payload['seances'][0]['titre']);
        self::assertSame('Playbooks', $payload['seances'][1]['titre']);
        self::assertSame(['Accueil', 'Apport théorique'], array_column($payload['seances'][0]['phases'], 'nom'));
    }

    /**
     * The four fields added on 2026-08-13 for content that used to have nowhere to go: differentiation
     * and watchPoints on the séquence, watchPoints and materials on the séance.
     *
     * Each fills a rung of a ladder that already existed rather than inventing a notion. materials
     * sits between SequenceTemplate::$supportsGeneraux (the séquence) and
     * SeancePhaseTemplate::$moyensSupports (the phase), which had no middle; watchPoints is what
     * SeancePhaseTemplate::$difficultes already says, one and two levels up. Before them, the Ansible
     * kit's "§ 9 Différenciation" and its "3 VM par étudiant" could only be poured into a field that
     * meant something else, or set aside (conception § 5).
     */
    public function testTheFieldsAddedForContentThatUsedToHaveNowhereToGoAreRead(): void
    {
        $payload = $this->importer->parse($this->document([
            'sequence' => [
                'titre' => 'Ansible',
                'differentiation' => 'En difficulté : binôme et inventaire fourni. Rapides : un rôle en autonomie.',
                'watchPoints' => "YAML : l'indentation.\nAnsible n'est pas Terraform.",
            ],
            'seances' => [[
                'titre' => 'Prendre la main sur un parc',
                'materials' => '3 VM Debian 12 par étudiant, vidéoprojecteur, support étudiant.',
                'watchPoints' => 'Interdire `ansible_password` en clair dès la première commande.',
            ]],
        ]));

        self::assertStringContainsString('binôme et inventaire fourni', (string) $payload['sequence']['differentiation']);
        self::assertStringContainsString("Ansible n'est pas Terraform", (string) $payload['sequence']['watchPoints']);
        self::assertStringContainsString('3 VM Debian 12 par étudiant', (string) $payload['seances'][0]['materials']);
        self::assertStringContainsString('ansible_password', (string) $payload['seances'][0]['watchPoints']);
    }

    /** Plain text like their neighbours, not HTML: nine screens render these escaped. */
    public function testTheNewFieldsAreReadAsPlainTextLikeTheirNeighbours(): void
    {
        $payload = $this->importer->parse($this->document([
            'sequence' => ['titre' => 'Ansible', 'watchPoints' => "| Écueil | Remède |\n|---|---|\n| **YAML** | Relire l'indentation |"],
            'seances' => [['titre' => 'S1']],
        ]));

        $watchPoints = (string) $payload['sequence']['watchPoints'];
        self::assertStringContainsString('| Écueil | Remède |', $watchPoints);
        self::assertStringContainsString('| YAML |', $watchPoints, 'bold markers are dropped, the table row stays');
        self::assertStringNotContainsString('|---|', $watchPoints, 'the separator row is punctuation for a parser nobody runs here');
        self::assertStringNotContainsString('<table>', $watchPoints);
    }

    public function testADocumentThatIsNotThisFormatIsRefusedWhole(): void
    {
        $this->expectException(SequenceImportException::class);
        $this->importer->parse('{"format":"moncampus-quiz/1","questions":[]}');
    }

    public function testTextThatIsNotJsonIsRefusedWhole(): void
    {
        $this->expectException(SequenceImportException::class);
        $this->importer->parse("Bien sûr ! Voici votre séquence :\n\n{\"format\"…");
    }

    public function testADocumentWithoutASingleSeanceIsRefusedWhole(): void
    {
        $this->expectException(SequenceImportException::class);
        $this->importer->parse($this->document(['seances' => []]));
    }

    /** Rule 6 of the format: a missing report is the sign of a model that skipped the instructions. */
    public function testADocumentWithoutAReportIsRefusedWhole(): void
    {
        $this->expectException(SequenceImportException::class);
        $this->importer->parse('{"format":"moncampus-sequence/1","sequence":{"titre":"T"},"seances":[{"titre":"S1"}]}');
    }

    public function testAnEmptyReportIsEnough(): void
    {
        $payload = $this->importer->parse($this->document([]));

        self::assertSame([], $payload['report']['deduit']);
        self::assertSame([], $payload['report']['nonPlace']);
        self::assertSame([], $payload['report']['vide']);
        self::assertFalse($payload['report']['declaresAnything']);
    }

    public function testTheReportIsCarriedThroughAsWrittenLines(): void
    {
        $payload = $this->importer->parse($this->document([
            'rapport' => [
                'deduit' => ['seances[0].evaluationNature — déduit de « Évaluation formative »'],
                'nonPlace' => [['titre' => '§ 9 Différenciation', 'contenu' => "- Étudiants en difficulté : playbooks à trous\n- Étudiants rapides : défis"]],
                'vide' => ['seances[*].cahierDeTexteDescription — absent du support'],
            ],
        ]));

        self::assertSame(['seances[0].evaluationNature — déduit de « Évaluation formative »'], $payload['report']['deduit']);
        self::assertSame('§ 9 Différenciation', $payload['report']['nonPlace'][0]['titre']);
        self::assertStringContainsString('playbooks à trous', (string) $payload['report']['nonPlace'][0]['contenu']);
        self::assertSame(['seances[*].cahierDeTexteDescription — absent du support'], $payload['report']['vide']);
    }

    /**
     * An unplaced block carries the text that had nowhere to go, because "verser dans un champ" has
     * nothing to pour otherwise: the source document is in the teacher's conversation, not here, and
     * pouring the label "§ 9 Différenciation" into supportsGeneraux would write a heading over an
     * empty hole. A bare string is still read - documents converted by a model that answered in the
     * older shape must not be refused - and the review screen then offers "Écarter" alone.
     */
    public function testAnUnplacedBlockWithoutItsTextCanBeNamedButNotPoured(): void
    {
        $payload = $this->importer->parse($this->document([
            'rapport' => ['nonPlace' => ['§ 11 Points de vigilance'], 'deduit' => [], 'vide' => []],
        ]));

        self::assertSame('§ 11 Points de vigilance', $payload['report']['nonPlace'][0]['titre']);
        self::assertNull($payload['report']['nonPlace'][0]['contenu']);
    }

    /** The Markdown of an unplaced block is flattened like any other plain-text field. */
    public function testAnUnplacedBlockIsReadAsPlainText(): void
    {
        $payload = $this->importer->parse($this->document([
            'rapport' => ['nonPlace' => [['titre' => '§ 9', 'contenu' => '**Gras** et [lien](https://x.test)']], 'deduit' => [], 'vide' => []],
        ]));

        self::assertSame('Gras et lien (https://x.test)', $payload['report']['nonPlace'][0]['contenu']);
    }

    public function testDurationsAreReadIntoMinutes(): void
    {
        $payload = $this->importer->parse($this->document([
            'seances' => [['titre' => 'S1', 'duree' => '4 h', 'phases' => [['nom' => 'Accueil', 'duree' => '20 min'], ['nom' => 'Apport', 'duree' => '1 h 15']]]],
        ]));

        self::assertSame('240', $payload['seances'][0]['duree']);
        self::assertSame('20', $payload['seances'][0]['phases'][0]['duree']);
        self::assertSame('75', $payload['seances'][0]['phases'][1]['duree']);
    }

    /**
     * The trap the format's "durations carry their unit" rule exists for: the column is DECIMAL(10,2)
     * MINUTES, so "1.5" meant as "1 h 30" would be stored and shown as "2 min". It is dropped and
     * said out loud rather than stored and believed.
     */
    public function testABareNumberIsDroppedAndReported(): void
    {
        $payload = $this->importer->parse($this->document([
            'seances' => [['titre' => 'S1', 'duree' => '1.5', 'phases' => [['nom' => 'Accueil', 'duree' => 240]]]],
        ]));

        self::assertNull($payload['seances'][0]['duree']);
        self::assertNull($payload['seances'][0]['phases'][0]['duree']);
        self::assertCount(2, $payload['warnings']);
    }

    public function testAnUnknownEvaluationNatureIsDroppedAndReported(): void
    {
        $payload = $this->importer->parse($this->document([
            'seances' => [
                ['titre' => 'S1', 'evaluationNature' => 'formative'],
                ['titre' => 'S2', 'evaluationNature' => 'sommative-orale'],
            ],
        ]));

        self::assertSame('formative', $payload['seances'][0]['evaluationNature']);
        self::assertNull($payload['seances'][1]['evaluationNature']);
        self::assertCount(1, $payload['warnings']);
    }

    /**
     * Ten of the eleven text fields are plain text - escaped under `pre-wrap`, edited in a bare
     * textarea. A pipe table stays as its lines: it still reads as a table there, and HTML would
     * reach the teacher as tags.
     */
    public function testPlainTextFieldsKeepTheirTableLinesAndLoseTheirMarkup(): void
    {
        $payload = $this->importer->parse($this->document([
            'sequence' => ['titre' => 'T', 'capacitesAttendues' => "| Code | Objectif |\n|---|---|\n| O1 | **Expliquer** le principe |"],
        ]));

        // The separator row goes: it is punctuation for a parser that does not exist at the other
        // end of a plain-text field (App\Util\MarkdownRenderer::toPlainText).
        self::assertSame("| Code | Objectif |\n| O1 | Expliquer le principe |", $payload['sequence']['capacitesAttendues']);
        self::assertStringNotContainsString('<', (string) $payload['sequence']['capacitesAttendues']);
    }

    /** The one HTML field, and the only one a *student* ever reads once the séance is instantiated. */
    public function testTheCahierDeTexteBecomesSanitizedHtml(): void
    {
        $payload = $this->importer->parse($this->document([
            'seances' => [['titre' => 'S1', 'cahierDeTexteDescription' => "Au programme :\n\n| Étape | Durée |\n|---|---|\n| Ping | 20 min |"]],
        ]));

        $html = (string) $payload['seances'][0]['cahierDeTexteDescription'];
        self::assertStringContainsString('<p>Au programme :</p>', $html);
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<td>Ping</td>', $html);
    }

    public function testTheCahierDeTexteCannotCarryAScript(): void
    {
        $payload = $this->importer->parse($this->document([
            'seances' => [['titre' => 'S1', 'cahierDeTexteDescription' => '<script>alert(1)</script>Bonjour']],
        ]));

        self::assertStringNotContainsString('<script', (string) $payload['seances'][0]['cahierDeTexteDescription']);
    }

    public function testAnUntitledSeanceOrPhaseIsNamedByItsPlaceRatherThanDropped(): void
    {
        $payload = $this->importer->parse($this->document([
            'seances' => [['titre' => '  ', 'phases' => [['nom' => '', 'contenu' => 'Un contenu qui vaut la peine']]]],
        ]));

        // Content is never dropped for want of a title - the review screen is where a name is fixed.
        self::assertNotSame('', $payload['seances'][0]['titre']);
        self::assertNotSame('', $payload['seances'][0]['phases'][0]['nom']);
        self::assertSame('Un contenu qui vaut la peine', $payload['seances'][0]['phases'][0]['contenu']);
        self::assertCount(2, $payload['warnings']);
    }

    /** Bounds are a warning, not a refusal: the real document arrives as it is. */
    public function testGoingOverTheBoundsWarnsAndKeepsEverything(): void
    {
        $seances = [];
        for ($index = 0; $index < SequenceJsonImporter::MAX_SEANCES + 1; ++$index) {
            $seances[] = ['titre' => 'S'.$index];
        }

        $payload = $this->importer->parse($this->document(['seances' => $seances]));

        self::assertCount(SequenceJsonImporter::MAX_SEANCES + 1, $payload['seances']);
        self::assertNotSame([], $payload['warnings']);
    }

    public function testAnOverlongFieldIsKeptAndReported(): void
    {
        $payload = $this->importer->parse($this->document([
            'sequence' => ['titre' => 'T', 'objectifs' => str_repeat('a', SequenceJsonImporter::MAX_FIELD_LENGTH + 1)],
        ]));

        self::assertSame(SequenceJsonImporter::MAX_FIELD_LENGTH + 1, \strlen((string) $payload['sequence']['objectifs']));
        self::assertNotSame([], $payload['warnings']);
    }

    public function testBlocsAcceptASingleLabelAsWellAsAList(): void
    {
        $payload = $this->importer->parse($this->document(['sequence' => ['titre' => 'T', 'blocs' => 'Bloc 1']]));

        self::assertSame(['Bloc 1'], $payload['sequence']['blocs']);
    }

    public function testTheTotalsTheReviewScreenShowsAreCounted(): void
    {
        $payload = $this->importer->parse($this->document([
            'seances' => [
                ['titre' => 'S1', 'duree' => '4 h', 'phases' => [['nom' => 'A', 'duree' => '20 min'], ['nom' => 'B', 'duree' => '3 h 40']]],
                ['titre' => 'S2', 'phases' => [['nom' => 'C']]],
            ],
        ]));

        self::assertSame(2, $payload['counts']['seances']);
        self::assertSame(3, $payload['counts']['phases']);
    }

    /**
     * The Ansible kit's séance 1 really is 245 minutes of phases inside a 240-minute séance, plus a
     * 10-minute break that is not a phase. The screen says so and creates it anyway - it is the
     * teacher's own document, not an error to correct.
     */
    public function testPhasesOverrunningTheirSeanceAreReportedNotCorrected(): void
    {
        $payload = $this->importer->parse($this->document([
            'seances' => [['titre' => 'S1', 'duree' => '4 h', 'phases' => [['nom' => 'A', 'duree' => '125 min'], ['nom' => 'B', 'duree' => '2 h']]]],
        ]));

        self::assertSame('240', $payload['seances'][0]['duree']);
        self::assertSame(245, $payload['seances'][0]['phasesMinutes']);
        self::assertTrue($payload['seances'][0]['overruns']);
    }

    /** @param array<string, mixed> $overrides */
    private function document(array $overrides): string
    {
        return json_encode($overrides + [
            'format' => 'moncampus-sequence/1',
            'sequence' => ['titre' => 'Une séquence'],
            'seances' => [['titre' => 'Une séance']],
            'rapport' => ['deduit' => [], 'nonPlace' => [], 'vide' => []],
        ], \JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\LibraryBlocTag;
use App\Entity\LibraryNiveauTag;
use App\Entity\LibraryOptionTag;
use App\Entity\SeancePhaseTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\EvaluationNature;
use App\Service\SequenceJsonExporter;
use App\Service\SequenceJsonImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A séquence written back out as "moncampus-sequence/1".
 *
 * The test that matters is the **round trip**, and it is the reason the export was worth building at
 * all: what comes out has to go back in. That is what unlocks revision - « ajoute une séance de
 * remédiation à cette séquence » - and it documents the format better than any page of prose, because
 * a format with a working exporter cannot quietly disagree with its own importer.
 *
 * The one place the two could disagree is the duration. The columns are a DECIMAL(10,2) of MINUTES and
 * the format insists a duration carries its unit, so the exporter writes "240 min": unambiguous to
 * App\Util\DurationParser, and immune to the "1.5 written for 1 h 30" trap that the format's rule
 * exists for.
 */
class SequenceJsonExporterTest extends TestCase
{
    private SequenceJsonExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new SequenceJsonExporter();
    }

    public function testItAnnouncesTheFormatTheImporterReads(): void
    {
        $document = $this->decode($this->exporter->export($this->sequence()));

        self::assertSame(SequenceJsonImporter::FORMAT, $this->at($document, 'format'));
    }

    /**
     * `rapport` is written even though an export deduced nothing and placed everything: the importer
     * refuses a document without it, so an export that omitted it could not be read back by the very
     * reader it exists to feed.
     */
    public function testTheReportBlockIsWrittenEvenThoughItIsEmpty(): void
    {
        $document = $this->decode($this->exporter->export($this->sequence()));

        self::assertSame(['deduit' => [], 'nonPlace' => [], 'vide' => []], $this->at($document, 'rapport'));
    }

    public function testEveryTextFieldOfTheThreeLevelsIsWrittenOut(): void
    {
        $document = $this->decode($this->exporter->export($this->sequence()));

        self::assertSame('Automatisation avec Ansible', $this->at($document, 'sequence.titre'));
        self::assertSame('BTS SIO 2', $this->at($document, 'sequence.niveau'));
        self::assertSame('SISR', $this->at($document, 'sequence.option'));
        self::assertSame(['Bloc 1', 'Bloc 2'], $this->at($document, 'sequence.blocs'));
        self::assertSame('Déployer une infrastructure', $this->at($document, 'sequence.objectifs'));
        // The four fields of 2026-08-13 travel too, or a round trip would quietly lose them.
        self::assertSame('Binôme et inventaire fourni', $this->at($document, 'sequence.differentiation'));
        self::assertSame('YAML : jamais de tabulation', $this->at($document, 'sequence.watchPoints'));
        self::assertSame('3 VM par étudiant', $this->at($document, 'seances.0.materials'));
        self::assertSame('Interdire ansible_password', $this->at($document, 'seances.0.watchPoints'));
    }

    /** A duration carries its unit, in the one unit the columns hold. */
    public function testDurationsAreWrittenInMinutesWithTheirUnit(): void
    {
        $document = $this->decode($this->exporter->export($this->sequence()));

        self::assertSame('240 min', $this->at($document, 'seances.0.duree'));
        self::assertSame('20 min', $this->at($document, 'seances.0.phases.0.duree'));
    }

    /** A field nobody filled is absent rather than null: the document stays readable by a human. */
    public function testAnEmptyFieldIsLeftOutRatherThanWrittenAsNull(): void
    {
        $sequence = new SequenceTemplate(new User('prof'));
        $sequence->setTitre('Séquence nue');
        $seance = new SeanceTemplate($sequence);
        $seance->setTitre('Séance nue');
        $sequence->getSeanceTemplates()->add($seance);

        $document = $this->decode($this->exporter->export($sequence));

        self::assertArrayNotHasKey('objectifs', $this->branch($document, 'sequence'));
        self::assertArrayNotHasKey('duree', $this->branch($document, 'seances.0'));
        self::assertSame([], $this->at($document, 'seances.0.phases'));
    }

    /**
     * The round trip, and the whole point: exported, re-read, and the same séquence comes back.
     */
    public function testWhatItWritesIsWhatTheImporterReadsBack(): void
    {
        $json = $this->exporter->export($this->sequence());

        $payload = $this->importer()->parse($json, 'export.json');

        self::assertSame([], $payload['warnings'], 'a document this application produced must import without a single warning');
        self::assertSame('Automatisation avec Ansible', $payload['sequence']['titre']);
        self::assertSame('BTS SIO 2', $payload['sequence']['niveau']);
        self::assertSame(['Bloc 1', 'Bloc 2'], $payload['sequence']['blocs']);
        self::assertSame('Binôme et inventaire fourni', $payload['sequence']['differentiation']);
        self::assertSame(1, $payload['counts']['seances']);
        self::assertSame(2, $payload['counts']['phases']);
        // Minutes out, minutes back - not "4" hours, not "240" of something unnamed.
        self::assertSame('240', $payload['seances'][0]['duree']);
        self::assertSame('20', $payload['seances'][0]['phases'][0]['duree']);
        self::assertSame('formative', $payload['seances'][0]['evaluationNature']);
        self::assertSame('3 VM par étudiant', $payload['seances'][0]['materials']);
    }

    /** The cahier de texte is the one HTML field, and it survives as readable Markdown-ish text. */
    public function testTheCahierDeTexteIsWrittenAsTextRatherThanAsTags(): void
    {
        $document = $this->decode($this->exporter->export($this->sequence()));

        $cahier = $this->text($document, 'seances.0.cahierDeTexteDescription');
        self::assertStringNotContainsString('<p>', $cahier);
        self::assertStringContainsString('Ping SUCCESS', $cahier);
    }

    /**
     * One value of the exported document, named by its dotted path - "seances.0.phases.0.duree".
     *
     * Walking a path is what keeps these assertions readable *and* typed: a chain of raw offsets on a
     * decoded document is a chain of `mixed`, which this repository's checkExplicitMixed refuses, and
     * rightly - nothing had checked the shape it was relying on. Every step here asserts the shape it
     * just used, so a wrong path fails as a wrong path rather than as a null comparison further down.
     */
    private function at(mixed $node, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            self::assertIsArray($node, 'reading "'.$path.'" ran out of document at "'.$key.'"');
            self::assertArrayHasKey($key, $node, 'reading "'.$path.'" found no "'.$key.'"');
            $node = $node[$key];
        }

        return $node;
    }

    /** @return array<array-key, mixed> */
    private function branch(mixed $node, string $path): array
    {
        $branch = $this->at($node, $path);
        self::assertIsArray($branch);

        return $branch;
    }

    private function text(mixed $node, string $path): string
    {
        $value = $this->at($node, $path);
        self::assertIsString($value);

        return $value;
    }

    /** @return array<array-key, mixed> */
    private function decode(string $json): array
    {
        $document = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }

    private function importer(): SequenceJsonImporter
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return 'fr';
            }
        };

        return new SequenceJsonImporter($translator, new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements()));
    }

    private function sequence(): SequenceTemplate
    {
        $teacher = new User('prof-001');

        $sequence = new SequenceTemplate($teacher);
        $sequence->setTitre('Automatisation avec Ansible');
        $sequence->setObjectifs('Déployer une infrastructure');
        $sequence->setDifferentiation('Binôme et inventaire fourni');
        $sequence->setWatchPoints('YAML : jamais de tabulation');

        $sequence->setNiveau(new LibraryNiveauTag($teacher, 'BTS SIO 2'));
        $sequence->setOption(new LibraryOptionTag($teacher, 'SISR'));
        foreach (['Bloc 1', 'Bloc 2'] as $label) {
            $sequence->addBloc(new LibraryBlocTag($teacher, $label));
        }

        $seance = new SeanceTemplate($sequence);
        $seance->setTitre('Prendre la main sur un parc');
        $seance->setOrdre(1);
        $seance->setDuree('240');
        $seance->setEvaluationNature(EvaluationNature::Formative);
        $seance->setMaterials('3 VM par étudiant');
        $seance->setWatchPoints('Interdire ansible_password');
        $seance->setCahierDeTexteDescription('<p>Ping SUCCESS sur les deux hôtes</p>');
        $sequence->getSeanceTemplates()->add($seance);

        foreach ([['Accueil', '20'], ['Atelier', '75']] as $index => [$nom, $duree]) {
            $phase = new SeancePhaseTemplate($seance);
            $phase->setOrdre($index + 1);
            $phase->setNom($nom);
            $phase->setDuree($duree);
            $phase->setContenu('Contenu de '.$nom);
            $seance->getSeancePhaseTemplates()->add($phase);
        }

        return $sequence;
    }
}

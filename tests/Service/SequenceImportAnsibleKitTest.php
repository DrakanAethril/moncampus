<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\SeanceTemplate;
use App\Entity\User;
use App\Repository\LibraryBlocTagRepository;
use App\Repository\LibraryNiveauTagRepository;
use App\Repository\LibraryOptionTagRepository;
use App\Service\LibraryTagResolver;
use App\Service\SequenceImportPouring;
use App\Service\SequenceImportWriter;
use App\Service\SequenceJsonImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The acceptance test of the séquence import, written before the screens: a real kit goes in.
 *
 * The kit is `design/comparaison/ansible/` - a BTS SIO SISR séquence of four 4-hour séances, 157
 * files, written for a classroom and not for this application. tests/Fixtures/sequence-ansible.json
 * is that kit transposed by hand into "moncampus-sequence/1", the way the assistant's prompt asks a
 * model to do it. What it proves is not that a language model works, which no test can: it is that
 * the format is wide enough for a real document, and that what the format cannot hold is *named*.
 *
 * The five blocks in "Non placé" are the point. Each is a piece of a real teacher's sheet that
 * MonCampus has no field for, and the panel is the only thing between that and a séquence the
 * teacher believes is complete (conception § 5).
 *
 * @phpstan-import-type SequenceImportPayload from SequenceJsonImporter
 */
class SequenceImportAnsibleKitTest extends TestCase
{
    private SequenceJsonImporter $importer;

    protected function setUp(): void
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

        $this->importer = new SequenceJsonImporter($translator, new HtmlSanitizer((new HtmlSanitizerConfig())->allowSafeElements()));
    }

    public function testTheWholeKitIsReadWithoutASingleWarning(): void
    {
        $payload = $this->parseKit();

        self::assertSame([], $payload['warnings']);
        self::assertSame('Automatisation de l\'administration avec Ansible', $payload['sequence']['titre']);
        self::assertSame('BTS SIO 2', $payload['sequence']['niveau']);
        self::assertSame('SISR', $payload['sequence']['option']);
        self::assertSame(['Bloc 1', 'Bloc 2', 'Bloc 3'], $payload['sequence']['blocs']);
    }

    /**
     * Four séances of four hours, and 26 phases rather than the 24 the design's mockup sketched:
     * the rule the design itself settled on - "la pause est une phase, nommée comme telle" - adds
     * one to each of the three teaching séances, and the fourth is organised in five moments rather
     * than six.
     */
    public function testTheFourSeancesAndTheirPhasesSurvive(): void
    {
        $payload = $this->parseKit();

        self::assertSame(4, $payload['counts']['seances']);
        self::assertSame(26, $payload['counts']['phases']);
        self::assertSame(['240', '240', '240', '240'], array_column($payload['seances'], 'duree'));
        self::assertSame('Prendre la main sur un parc sans agent', $payload['seances'][0]['titre']);
        self::assertSame(['formative', 'formative', 'formative', 'summative'], array_column($payload['seances'], 'evaluationNature'));
    }

    /**
     * Séance 1 really is 245 minutes of phases inside a 240-minute séance - 20 + 40 + 75 + 10 + 75 +
     * 20 + 15. The screen says so; nothing is corrected. It is the teacher's own document.
     */
    public function testTheOverrunOfSeanceOneIsSeenAndKept(): void
    {
        $payload = $this->parseKit();

        self::assertSame(255, $payload['seances'][0]['phasesMinutes']);
        self::assertTrue($payload['seances'][0]['overruns']);
    }

    /** The O1→O13 grid reaches capacitesAttendues as a table, in a field that cannot hold HTML. */
    public function testTheObjectivesGridSurvivesAsATable(): void
    {
        $capacites = (string) $this->parseKit()['sequence']['capacitesAttendues'];

        self::assertStringContainsString('| O1 | Expliquer le principe agentless', $capacites);
        self::assertStringContainsString('| O13 |', $capacites);
        self::assertStringNotContainsString('<table>', $capacites);
    }

    /**
     * The fixture was transposed before the fields of 2026-08-13 existed, and it is kept that way on
     * purpose: it is now also the regression case for a document a teacher converted with the previous
     * prompt and pastes today. Its five blocks are still declared unplaced, and four of them now have
     * a right field to be poured into rather than the nearest one that meant something else - which is
     * what the end-to-end test below does.
     */
    public function testTheFiveBlocksTheConversionCouldNotPlaceAreAllNamedAndCarryTheirText(): void
    {
        $report = $this->parseKit()['report'];

        self::assertCount(5, $report['nonPlace']);
        self::assertSame([
            '§ 9 Différenciation',
            '§ 11 Points de vigilance de la séquence',
            'Séance 1 — Points de vigilance',
            'Séance 1 — Matériel et supports',
            'Séance 1 — Livrable et jalon à valider',
        ], array_column($report['nonPlace'], 'titre'));

        foreach ($report['nonPlace'] as $block) {
            // Named without its text, the block could be set aside but never poured anywhere.
            self::assertNotNull($block['contenu'], $block['titre'].' must carry the text that had nowhere to go');
        }
    }

    public function testWhatWasDeducedAndWhatStayedEmptyAreDeclaredToo(): void
    {
        $report = $this->parseKit()['report'];

        self::assertTrue($report['declaresAnything']);
        // The kit's séance 1 carries a diagnostic *and* a formative evaluation; the column holds one.
        self::assertStringContainsString('diagnostic', implode("\n", $report['deduit']));
        self::assertStringContainsString('cahierDeTexteDescription', implode("\n", $report['vide']));
    }

    /** End to end: the kit, poured and confirmed, is a séquence in a teacher's library. */
    public function testTheKitBecomesASequenceOnceThePanelHasBeenAnsweredAndConfirmed(): void
    {
        $payload = $this->parseKit();

        // Four of the five blocks now have a field of their own (2026-08-13). Only the fifth -
        // livrable and jalon - is still set aside, and deliberately so: a livrable already lives in
        // the application as an AssignmentExpectedProduction, and a free-text copy of it on the
        // séance template would be a second place to read what the student must hand in.
        $payload = SequenceImportPouring::apply($payload, [
            0 => 'sequence.differentiation',
            1 => 'sequence.watchPoints',
            2 => 'seances.0.watchPoints',
            3 => 'seances.0.materials',
            4 => SequenceImportPouring::DISCARD,
        ]);

        $teacher = new User('prof-001');
        $sequence = $this->writer()->createSequence($teacher, $payload);

        self::assertSame([], $payload['report']['nonPlace'], 'every block has had its decision');
        self::assertCount(4, $sequence->getSeanceTemplates());
        self::assertSame(26, array_sum(array_map(
            static fn ($seance): int => $seance->getSeancePhaseTemplates()->count(),
            $sequence->getSeanceTemplates()->toArray(),
        )));

        // Each block keeps its own heading, so a teacher reading the field three months later can
        // tell where the text came from.
        self::assertStringStartsWith('§ 9 Différenciation', (string) $sequence->getDifferentiation());
        self::assertStringContainsString('playbooks « à trous »', (string) $sequence->getDifferentiation());
        self::assertStringContainsString('Idempotence', (string) $sequence->getWatchPoints());

        $first = $sequence->getSeanceTemplates()->first();
        self::assertInstanceOf(SeanceTemplate::class, $first);
        self::assertStringContainsString('ansible_password', (string) $first->getWatchPoints());
        self::assertStringContainsString('3 VM', (string) $first->getMaterials());
        // And the field the teacher used to pour all of this into stays untouched.
        self::assertStringStartsWith('Nœud de contrôle', (string) $sequence->getSupportsGeneraux());
    }

    /** @return SequenceImportPayload */
    private function parseKit(): array
    {
        $json = file_get_contents(\dirname(__DIR__).'/Fixtures/sequence-ansible.json');
        self::assertIsString($json);

        return $this->importer->parse($json, 'sequence-ansible.json');
    }

    private function writer(): SequenceImportWriter
    {
        $niveau = $this->createStub(LibraryNiveauTagRepository::class);
        $option = $this->createStub(LibraryOptionTagRepository::class);
        $blocs = $this->createStub(LibraryBlocTagRepository::class);
        $niveau->method('findOneByTeacherAndLabel')->willReturn(null);
        $option->method('findOneByTeacherAndLabel')->willReturn(null);
        $blocs->method('findOneByTeacherAndLabel')->willReturn(null);

        $entityManager = $this->createStub(EntityManagerInterface::class);

        return new SequenceImportWriter($entityManager, new LibraryTagResolver($entityManager), $niveau, $option, $blocs);
    }
}

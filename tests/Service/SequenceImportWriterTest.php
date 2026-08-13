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
use App\Repository\LibraryBlocTagRepository;
use App\Repository\LibraryNiveauTagRepository;
use App\Repository\LibraryOptionTagRepository;
use App\Service\LibraryTagResolver;
use App\Service\SequenceImportWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The confirmed payload turned into entities - the only place in the import that writes.
 *
 * Three destinations, and the rule they share is that the import never replaces: it creates a
 * séquence, or appends séances to one, or fills the empty fields of a séance. A teacher who converts
 * the same kit twice gets duplicates, which is visible and undoable; a teacher whose second
 * conversion silently overwrote a month of edits would have no way back.
 */
class SequenceImportWriterTest extends TestCase
{
    private User $teacher;

    private SequenceImportWriter $writer;

    protected function setUp(): void
    {
        $this->teacher = new User('prof-001');
        $this->writer = $this->writerWith($this->createStub(EntityManagerInterface::class));
    }

    private function writerWith(EntityManagerInterface $entityManager): SequenceImportWriter
    {
        // No tag exists yet for this teacher, so every label resolves to a fresh one.
        $niveau = $this->createStub(LibraryNiveauTagRepository::class);
        $option = $this->createStub(LibraryOptionTagRepository::class);
        $blocs = $this->createStub(LibraryBlocTagRepository::class);
        $niveau->method('findOneByTeacherAndLabel')->willReturn(null);
        $option->method('findOneByTeacherAndLabel')->willReturn(null);
        $blocs->method('findOneByTeacherAndLabel')->willReturn(null);

        return new SequenceImportWriter($entityManager, new LibraryTagResolver($entityManager), $niveau, $option, $blocs);
    }

    public function testCreatesTheSequenceItsSeancesAndItsPhasesInOrder(): void
    {
        $sequence = $this->writer->createSequence($this->teacher, $this->payload(), 4);

        self::assertSame('Automatisation avec Ansible', $sequence->getTitre());
        self::assertSame($this->teacher, $sequence->getTeacher());
        // The library list orders on it, and the controller counts the existing rows to get here.
        self::assertSame(4, $sequence->getOrder());
        self::assertCount(2, $sequence->getSeanceTemplates());

        $first = $sequence->getSeanceTemplates()->first();
        self::assertInstanceOf(SeanceTemplate::class, $first);
        self::assertSame('Prendre la main sur un parc', $first->getTitre());
        self::assertSame('240', $first->getDuree());
        self::assertSame(EvaluationNature::Formative, $first->getEvaluationNature());
        self::assertSame(1, $first->getOrdre());
        self::assertCount(2, $first->getSeancePhaseTemplates());
        self::assertSame([1, 2], array_map(static fn ($phase) => $phase->getOrdre(), $first->getSeancePhaseTemplates()->toArray()));
        self::assertSame('Accueil', $first->getSeancePhaseTemplates()->first()->getNom());
        self::assertSame('20', $first->getSeancePhaseTemplates()->first()->getDuree());
    }

    public function testCarriesEveryTextFieldAcrossTheThreeLevels(): void
    {
        $sequence = $this->writer->createSequence($this->teacher, $this->payload());
        $seance = $sequence->getSeanceTemplates()->first();
        $phase = $seance->getSeancePhaseTemplates()->first();

        self::assertSame('Déployer une infrastructure Linux', $sequence->getObjectifs());
        self::assertSame('SSH, apt, droits Unix', $sequence->getPreRequis());
        self::assertSame('Travail personnel : lire la documentation', $seance->getApresDescription());
        self::assertSame('<p>Ping SUCCESS sur les deux hôtes</p>', $seance->getCahierDeTexteDescription());
        self::assertSame('Situation déclenchante projetée', $phase->getContenu());
        self::assertSame('Anime, régule', $phase->getEnseignant());
        // The four fields of 2026-08-13, written like the rest rather than through a side path.
        self::assertSame('En difficulté : binôme et inventaire fourni', $sequence->getDifferentiation());
        self::assertSame("YAML : l'indentation, jamais de tabulation", $sequence->getWatchPoints());
        self::assertSame('3 VM Debian 12 par étudiant, vidéoprojecteur', $seance->getMaterials());
        self::assertSame('Interdire ansible_password en clair', $seance->getWatchPoints());
    }

    /**
     * "Compléter une séance" protects the new fields exactly like the old ones: a teacher who already
     * wrote their own points de vigilance keeps them, whatever the conversion proposes.
     */
    public function testCompletingASeanceProtectsTheNewFieldsToo(): void
    {
        $sequence = new SequenceTemplate($this->teacher);
        $seance = new SeanceTemplate($sequence);
        $seance->setWatchPoints('Mes propres points de vigilance');

        $this->writer->completeSeance($seance, $this->payload());

        self::assertSame('Mes propres points de vigilance', $seance->getWatchPoints());
        self::assertSame('3 VM Debian 12 par étudiant, vidéoprojecteur', $seance->getMaterials(), 'the empty one is filled');
    }

    /**
     * Every entity it builds is handed to the entity manager, séances and phases included.
     *
     * None of these associations cascades persist - this repository maps none of them that way, and
     * App\Command\ImportNotionSequencesCommand persists its séquence, its séances and its phases one
     * by one for the same reason. Persisting only the séquence therefore reaches Doctrine as
     * "multiple non-persisted new entities were found through the given association graph" at flush
     * time, which is a 500 on the confirmation screen and cost nothing to reproduce in a browser.
     */
    public function testEveryEntityItBuildsIsPersisted(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $persisted = [];
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity::class;
        });

        $this->writerWith($entityManager)->createSequence($this->teacher, $this->payload());

        // 1 séquence + 2 séances + 2 phases, plus the four tags the labels resolved to.
        self::assertCount(1, array_filter($persisted, static fn (string $class): bool => SequenceTemplate::class === $class));
        self::assertCount(2, array_filter($persisted, static fn (string $class): bool => SeanceTemplate::class === $class));
        self::assertCount(2, array_filter($persisted, static fn (string $class): bool => SeancePhaseTemplate::class === $class));
    }

    /** It never flushes: the controller owns the transaction, exactly as LibraryTagResolver does. */
    public function testItNeverFlushes(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $this->writerWith($entityManager)->createSequence($this->teacher, $this->payload());
    }

    /** Niveau/Option/Blocs are free text per teacher: an unknown label becomes that teacher's tag. */
    public function testTagsAreCreatedForThisTeacherWhenTheyDoNotExistYet(): void
    {
        $sequence = $this->writer->createSequence($this->teacher, $this->payload());

        self::assertInstanceOf(LibraryNiveauTag::class, $sequence->getNiveau());
        self::assertSame('BTS SIO 2', $sequence->getNiveau()->getLabel());
        self::assertInstanceOf(LibraryOptionTag::class, $sequence->getOption());
        self::assertCount(2, $sequence->getBlocs());
        self::assertContainsOnlyInstancesOf(LibraryBlocTag::class, $sequence->getBlocs());
    }

    public function testAppendingToAnExistingSequenceNumbersTheNewSeancesAfterTheOldOnes(): void
    {
        $existing = new SequenceTemplate($this->teacher);
        $existing->setTitre('Séquence déjà là');
        $alreadyThere = new SeanceTemplate($existing);
        $alreadyThere->setTitre('Séance déjà là');
        $alreadyThere->setOrdre(1);
        $existing->getSeanceTemplates()->add($alreadyThere);

        $this->writer->appendSeances($existing, $this->payload());

        self::assertCount(3, $existing->getSeanceTemplates());
        self::assertSame('Séquence déjà là', $existing->getTitre(), 'appending must not touch the séquence itself');
        self::assertSame([1, 2, 3], array_map(static fn ($seance) => $seance->getOrdre(), $existing->getSeanceTemplates()->toArray()));
    }

    /**
     * "Compléter une séance existante" fills the holes and nothing else. The teacher asked for the
     * missing half of a séance they had already started, not for their own wording to be replaced by
     * a model's.
     */
    public function testCompletingASeanceFillsOnlyItsEmptyFields(): void
    {
        $sequence = new SequenceTemplate($this->teacher);
        $seance = new SeanceTemplate($sequence);
        $seance->setTitre('Ma séance à moi');
        $seance->setObjectifs('Mes objectifs à moi');

        $this->writer->completeSeance($seance, $this->payload());

        self::assertSame('Ma séance à moi', $seance->getTitre());
        self::assertSame('Mes objectifs à moi', $seance->getObjectifs());
        self::assertSame('240', $seance->getDuree());
        self::assertSame('Travail personnel : lire la documentation', $seance->getApresDescription());
    }

    /** A séance that already has a déroulé keeps it: phases are appended, never interleaved. */
    public function testCompletingASeanceAppendsItsPhasesAfterTheExistingOnes(): void
    {
        $sequence = new SequenceTemplate($this->teacher);
        $seance = new SeanceTemplate($sequence);
        $seance->setTitre('Ma séance');
        $this->writer->completeSeance($seance, $this->payload());
        $this->writer->completeSeance($seance, $this->payload());

        self::assertCount(4, $seance->getSeancePhaseTemplates());
        self::assertSame([1, 2, 3, 4], array_map(static fn ($phase) => $phase->getOrdre(), $seance->getSeancePhaseTemplates()->toArray()));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'format' => 'sequence',
            'fileName' => 'import.json',
            'sequence' => [
                'titre' => 'Automatisation avec Ansible',
                'niveau' => 'BTS SIO 2', 'option' => 'SISR', 'blocs' => ['Bloc 1', 'Bloc 2'],
                'objectifs' => 'Déployer une infrastructure Linux',
                'capacitesAttendues' => null,
                'preRequis' => 'SSH, apt, droits Unix',
                'transversalites' => null, 'situationProblematique' => null, 'supportsGeneraux' => null,
                'differentiation' => 'En difficulté : binôme et inventaire fourni',
                'watchPoints' => "YAML : l'indentation, jamais de tabulation",
            ],
            'seances' => [
                [
                    'titre' => 'Prendre la main sur un parc', 'duree' => '240', 'evaluationNature' => 'formative',
                    'objectifs' => 'Expliquer le principe agentless', 'avantDescription' => null,
                    'apresDescription' => 'Travail personnel : lire la documentation',
                    'materials' => '3 VM Debian 12 par étudiant, vidéoprojecteur',
                    'watchPoints' => 'Interdire ansible_password en clair',
                    'cahierDeTexteDescription' => '<p>Ping SUCCESS sur les deux hôtes</p>',
                    'phases' => [
                        [
                            'nom' => 'Accueil', 'duree' => '20', 'contenu' => 'Situation déclenchante projetée',
                            'objectifs' => null, 'enseignant' => 'Anime, régule', 'etudiant' => null,
                            'moyensSupports' => null, 'difficultes' => null,
                        ],
                        [
                            'nom' => 'Apport théorique', 'duree' => '40', 'contenu' => null, 'objectifs' => null,
                            'enseignant' => null, 'etudiant' => null, 'moyensSupports' => null, 'difficultes' => null,
                        ],
                    ],
                    'phasesMinutes' => 60, 'overruns' => false,
                ],
                [
                    'titre' => 'Playbooks', 'duree' => null, 'evaluationNature' => null,
                    'objectifs' => null, 'avantDescription' => null, 'apresDescription' => null,
                    'materials' => null, 'watchPoints' => null,
                    'cahierDeTexteDescription' => null, 'phases' => [], 'phasesMinutes' => 0, 'overruns' => false,
                ],
            ],
            'report' => ['deduit' => [], 'nonPlace' => [], 'vide' => [], 'declaresAnything' => false],
            'warnings' => [],
            'counts' => ['seances' => 2, 'phases' => 2],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSeancePlacement;
use App\Entity\ProgressionSequence;
use App\Entity\SchoolYear;
use App\Entity\SeanceInstance;
use App\Entity\SeancePhaseInstance;
use App\Entity\Section;
use App\Entity\SequenceInstance;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\EvaluationNature;
use App\Service\ProgressionQualiopiBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The Qualiopi export's content, which is the half of that feature worth pinning: the layout is a
 * print stylesheet anyone can restyle, but a figure that does not match the timetable is what makes
 * the document worthless in an audit.
 *
 * So what is asserted here is the arithmetic and the provenance - hours counted from real
 * placements, a per-groupe séance counted once per group rather than once, a séance still unplaced
 * printed as such instead of dropped, and the déroulé read from the CLASS's copy.
 */
class ProgressionQualiopiBuilderTest extends TestCase
{
    private ProgressionQualiopiBuilder $builder;
    private Program $program;
    private Topic $topic;
    private User $teacher;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->builder = new ProgressionQualiopiBuilder();

        $schoolYear = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30'));
        $this->teacher = new User('teacher');
        $this->program = new Program('SIO-2 2026-2027', 'SIO-2', new Cohort('SIO-2', new Track('SIO', new Section('BTS'))), $schoolYear);
        $this->topic = new Topic('Cybersécurité', $this->program, new TopicGroup('Bloc 1', $this->program));
    }

    // The document's headline figures come from the placements, not from what was planned: "3 h
    // placées sur 4 h prévues" is a sentence an auditor can check against the timetable.
    public function testHoursAndDatesAreMeasuredFromThePlacements(): void
    {
        $progression = new Progression($this->topic, $this->teacher);
        $sequence = $this->sequence($progression, 'Séquence 1');

        $this->placedSeance($sequence, 'Séance 1', 120, '2026-09-01');
        $this->placedSeance($sequence, 'Séance 2', 60, '2026-09-08');

        $data = $this->builder->build($progression);

        self::assertSame(180, $data['totalPlacedMinutes']);
        self::assertSame(180, $data['totalPlannedMinutes']);
        self::assertSame('2026-09-01', $data['firstDay']?->format('Y-m-d'));
        self::assertSame('2026-09-08', $data['lastDay']?->format('Y-m-d'));
        self::assertSame(2, $data['seanceCount']);
        self::assertSame(2, $data['placedSeanceCount']);
    }

    // A séance taught once per groupe really is delivered twice, on two dates. Folding the two
    // placements into one line would under-report the hours actually given to the class.
    public function testAPerGroupSeanceIsPrintedOncePerGroup(): void
    {
        $progression = new Progression($this->topic, $this->teacher);
        $sequence = $this->sequence($progression, 'Cycle de TP');

        $seance = new ProgressionSeance($sequence, 'TP forensic');
        $seance->setPlannedMinutes(120);
        $seance->setSeanceInstance($this->seanceInstance('TP forensic'));

        foreach ([['2026-09-01', 'SLAM'], ['2026-09-02', 'SISR']] as [$day, $optionName]) {
            $placement = new ProgressionSeancePlacement($seance, $this->slot($day));
            $placement->setDurationMinutes(120);
            $placement->setOption($this->option($optionName));
        }

        $data = $this->builder->build($progression);

        $rows = $data['sequences'][0]['seances'];
        self::assertCount(2, $rows);
        self::assertSame('SLAM', $rows[0]['group']);
        self::assertSame('SISR', $rows[1]['group']);
        self::assertSame(240, $data['totalPlacedMinutes'], 'both deliveries count');

        // ...and the document has to be able to say so, or "240 min delivered for a 120 min séance"
        // reads as an error rather than as two groups being taught in turn.
        self::assertSame(1, $data['sequences'][0]['seanceCount']);
        self::assertSame(2, $data['sequences'][0]['deliveryCount']);
        self::assertSame(1, $data['perGroupSeanceCount']);
    }

    // The ordinary case says nothing about groups, so the document prints one count and no note.
    public function testAWholeClassSequenceReportsNoDuplication(): void
    {
        $progression = new Progression($this->topic, $this->teacher);
        $sequence = $this->sequence($progression, 'Séquence 1');
        $this->placedSeance($sequence, 'Séance 1', 60, '2026-09-01');

        $data = $this->builder->build($progression);

        self::assertSame(1, $data['sequences'][0]['seanceCount']);
        self::assertSame(1, $data['sequences'][0]['deliveryCount']);
        self::assertSame(0, $data['perGroupSeanceCount']);
    }

    // A séance the progression carries but has not placed yet belongs in the document as the plan -
    // and has to be visibly flagged rather than quietly dropped, which would overstate completion.
    public function testAnUnplacedSeanceIsKeptAndCounted(): void
    {
        $progression = new Progression($this->topic, $this->teacher);
        $sequence = $this->sequence($progression, 'Séquence 1');

        $this->placedSeance($sequence, 'Séance 1', 60, '2026-09-01');
        $unplaced = new ProgressionSeance($sequence, 'Séance 2');
        $unplaced->setPlannedMinutes(60);

        $data = $this->builder->build($progression);

        self::assertSame(1, $data['sequences'][0]['unplacedCount']);
        self::assertCount(2, $data['sequences'][0]['seances']);
        self::assertNull($data['sequences'][0]['seances'][1]['date']);
        self::assertSame(2, $data['seanceCount']);
        self::assertSame(1, $data['placedSeanceCount']);
    }

    // The méthodes section is the class's own déroulé - the phases of the SeanceInstance, in order,
    // not whatever the library model says today.
    public function testTheBreakdownComesFromTheClassCopy(): void
    {
        $progression = new Progression($this->topic, $this->teacher);
        $sequence = $this->sequence($progression, 'Séquence 1');

        $instance = $this->seanceInstance('Séance 1');
        foreach ([[1, 'Mise en activité'], [0, 'Lancement']] as [$ordre, $nom]) {
            $phase = new SeancePhaseInstance($instance);
            $phase->setOrdre($ordre);
            $phase->setNom($nom);
            $phase->setDuree('20.00');
            $phase->setEnseignant('Consigne et cadrage');
            $phase->setEtudiant('Travail en binôme');
            $phase->setMoyensSupports('Salle réseau · Wireshark');
        }

        $seance = new ProgressionSeance($sequence, 'Séance 1');
        $seance->setPlannedMinutes(60);
        $seance->setSeanceInstance($instance);
        $placement = new ProgressionSeancePlacement($seance, $this->slot('2026-09-01'));
        $placement->setDurationMinutes(60);

        $data = $this->builder->build($progression);

        $phases = $data['sequences'][0]['seances'][0]['phases'];
        self::assertCount(2, $phases);
        self::assertSame('Lancement', $phases[0]['name'], 'phases print in their own order, not insertion order');
        self::assertSame(20, $phases[0]['minutes']);
        self::assertSame('Travail en binôme', $phases[0]['student']);
        self::assertContains('Salle réseau · Wireshark', $data['methodSummary']);
    }

    // Both kinds of evidence under critère 7 end up in one table: the séances that carry a nature,
    // and the evaluations posed on the Carnet de notes.
    public function testEvaluationsCarriedBySeancesAreCounted(): void
    {
        $progression = new Progression($this->topic, $this->teacher);
        $sequence = $this->sequence($progression, 'Séquence 1');

        $seance = $this->placedSeance($sequence, 'Contrôle', 60, '2026-09-15');
        $seance->setEvaluationNature(EvaluationNature::Summative);

        $data = $this->builder->build($progression);

        self::assertSame(1, $data['evaluationCounts'][EvaluationNature::Summative->value]);
        self::assertSame('Contrôle', $data['evaluationRows'][0]['title']);
        self::assertSame('2026-09-15', $data['evaluationRows'][0]['date']?->format('Y-m-d'));
    }

    // The séquence half of the document reads the class's copy too - its objectives, not the
    // template's.
    public function testSequenceFieldsComeFromTheClassCopy(): void
    {
        $progression = new Progression($this->topic, $this->teacher);
        $instance = new SequenceInstance($this->program, $this->teacher);
        $instance->setTitre('Sécuriser un poste');
        $instance->setObjectifs('Objectifs propres à cette classe');
        $instance->setPreRequis('Bases réseau');
        $this->setId($instance, $this->nextId++);

        $sequence = new ProgressionSequence($progression, $instance);
        $this->placedSeance($sequence, 'Séance 1', 60, '2026-09-01');

        $data = $this->builder->build($progression);

        self::assertSame('Sécuriser un poste', $data['sequences'][0]['title']);
        self::assertSame('Objectifs propres à cette classe', $data['sequences'][0]['objectifs']);
        self::assertSame('Bases réseau', $data['sequences'][0]['preRequis']);
    }

    private function sequence(Progression $progression, string $title): ProgressionSequence
    {
        $instance = new SequenceInstance($this->program, $this->teacher);
        $instance->setTitre($title);
        $this->setId($instance, $this->nextId++);

        return new ProgressionSequence($progression, $instance);
    }

    private function placedSeance(ProgressionSequence $sequence, string $title, int $minutes, string $day): ProgressionSeance
    {
        $seance = new ProgressionSeance($sequence, $title);
        $seance->setPlannedMinutes($minutes);
        $seance->setSeanceInstance($this->seanceInstance($title));

        $placement = new ProgressionSeancePlacement($seance, $this->slot($day));
        $placement->setDurationMinutes($minutes);

        return $seance;
    }

    private function seanceInstance(string $title): SeanceInstance
    {
        $instance = new SeanceInstance($this->program, $this->teacher);
        $instance->setTitre($title);
        $this->setId($instance, $this->nextId++);

        return $instance;
    }

    private function slot(string $day): LessonSession
    {
        $session = new LessonSession($this->program);
        $session->setDay(new \DateTimeImmutable($day));
        $session->setStartHour(new \DateTimeImmutable($day.' 08:00'));
        $session->setEndHour(new \DateTimeImmutable($day.' 10:00'));
        $session->setTopic($this->topic);
        $this->setId($session, $this->nextId++);

        return $session;
    }

    private function option(string $shortName): Option
    {
        $option = new Option($shortName, $shortName, '#1B6BA8');
        $this->setId($option, crc32($shortName));

        return $option;
    }

    private function setId(object $entity, int $id): void
    {
        for ($class = new \ReflectionClass($entity); false !== $class; $class = $class->getParentClass()) {
            if ($class->hasProperty('id')) {
                $class->getProperty('id')->setValue($entity, $id);

                return;
            }
        }

        self::fail(\sprintf('%s has no id property to seed', $entity::class));
    }
}

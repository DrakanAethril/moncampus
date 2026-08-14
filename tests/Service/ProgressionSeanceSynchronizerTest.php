<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;
use App\Entity\SchoolYear;
use App\Entity\SeanceInstance;
use App\Entity\Section;
use App\Entity\SequenceInstance;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\EvaluationNature;
use App\Repository\ProgressionSeanceRepository;
use App\Service\ProgressionSeanceSynchronizer;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The one field pushed back from the class's copy of a séance onto the progression lines planning
 * it. What matters is that it IS pushed - the progression's D/F/S counters and the Qualiopi export
 * both read the copied value - and that nothing else follows it.
 */
class ProgressionSeanceSynchronizerTest extends TestCase
{
    private ProgressionSeanceRepository&Stub $repository;
    private ProgressionSeanceSynchronizer $synchronizer;
    private Program $program;
    private Topic $topic;
    private User $teacher;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(ProgressionSeanceRepository::class);
        $this->synchronizer = new ProgressionSeanceSynchronizer($this->repository);

        $schoolYear = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30'));
        $this->teacher = new User('teacher');
        $this->program = new Program('SIO-2 2026-2027', 'SIO-2', new Cohort('SIO-2', new Track('SIO', new Section('BTS'))), $schoolYear);
        $this->topic = new Topic('Cybersécurité', $this->program, new TopicGroup('Bloc 1', $this->program));
    }

    // Correcting the nature on the class's copy reaches the plan, which is where the counters and
    // the export read it from.
    public function testTheNatureReachesTheProgressionLine(): void
    {
        [$instance, $seance] = $this->pair(EvaluationNature::Diagnostic);
        $instance->setEvaluationNature(EvaluationNature::Summative);

        self::assertSame(1, $this->synchronizer->syncEvaluationNature($instance));
        self::assertSame(EvaluationNature::Summative, $seance->getEvaluationNature());
    }

    // Clearing it on the copy clears it on the plan too - otherwise the year keeps counting an
    // evaluation nobody gives any more.
    public function testClearingTheNatureClearsThePlan(): void
    {
        [$instance, $seance] = $this->pair(EvaluationNature::Formative);
        $instance->setEvaluationNature(null);

        self::assertSame(1, $this->synchronizer->syncEvaluationNature($instance));
        self::assertNull($seance->getEvaluationNature());
    }

    // Nothing to do when the two already agree - the count is what the caller would report, and a
    // no-op must not claim a change.
    public function testAnUnchangedNatureUpdatesNothing(): void
    {
        [$instance] = $this->pair(EvaluationNature::Summative);
        $instance->setEvaluationNature(EvaluationNature::Summative);

        self::assertSame(0, $this->synchronizer->syncEvaluationNature($instance));
    }

    // The title and the planned duration deliberately stay put: the progression line carries what
    // was planned, and its duration is what the placement arithmetic is built on.
    public function testOnlyTheNatureIsPushedBack(): void
    {
        [$instance, $seance] = $this->pair(null);
        $seance->setPlannedMinutes(55);
        $instance->setTitre('Titre revu pour la classe');
        $instance->setDuree('90.00');
        $instance->setEvaluationNature(EvaluationNature::Diagnostic);

        $this->synchronizer->syncEvaluationNature($instance);

        self::assertSame(EvaluationNature::Diagnostic, $seance->getEvaluationNature());
        self::assertSame('Séance planifiée', $seance->getTitle());
        self::assertSame(55, $seance->getPlannedMinutes());
    }

    /** @return array{0: SeanceInstance, 1: ProgressionSeance} */
    private function pair(?EvaluationNature $plannedNature): array
    {
        $instance = new SeanceInstance($this->program, $this->teacher);
        $instance->setTitre('Séance');

        $progression = new Progression($this->topic, $this->teacher);
        $sequenceInstance = new SequenceInstance($this->program, $this->teacher);
        $sequenceInstance->setTitre('Séquence');
        $sequence = new ProgressionSequence($progression, $sequenceInstance);

        $seance = new ProgressionSeance($sequence, 'Séance planifiée');
        $seance->setSeanceInstance($instance);
        $seance->setEvaluationNature($plannedNature);

        $this->repository->method('findBySeanceInstance')->willReturn([$seance]);

        return [$instance, $seance];
    }
}

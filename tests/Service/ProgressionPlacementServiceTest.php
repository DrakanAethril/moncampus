<?php

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SequenceInstance;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\Track;
use App\Entity\User;
use App\Entity\SeanceInstance;
use App\Repository\LessonSessionRepository;
use App\Repository\SeanceInstanceRepository;
use App\Service\ProgressionPlacementService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Covers the automatic-placement rules of design/design_handoff_progression/README.md §4 - the one
 * piece of this module every screen depends on being right, and the only part cheap to test in
 * isolation (pure logic over an in-memory list of créneaux, no DB). Same reasoning as
 * AlternancePeriodStatusResolverTest: not a signal that this repo is growing a full test suite.
 */
class ProgressionPlacementServiceTest extends TestCase
{
    // A stub, not a mock: the repository is purely the source of the créneau list here, and no
    // test cares how many times it is asked.
    private LessonSessionRepository&Stub $lessonSessionRepository;
    private SeanceInstanceRepository&Stub $seanceInstanceRepository;
    private ProgressionPlacementService $service;
    private Program $program;
    private Topic $topic;
    private int $nextSessionId = 1;

    protected function setUp(): void
    {
        $this->lessonSessionRepository = $this->createStub(LessonSessionRepository::class);
        $this->seanceInstanceRepository = $this->createStub(SeanceInstanceRepository::class);
        $this->service = new ProgressionPlacementService($this->lessonSessionRepository, $this->seanceInstanceRepository);

        $schoolYear = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30'));
        $cohort = new Cohort('SIO-2', new Track('SIO', new Section('BTS')));

        $this->program = new Program('SIO-2 2026-2027', 'SIO-2', $cohort, $schoolYear);
        $this->topic = new Topic('Cybersécurité', $this->program, new TopicGroup('Bloc 1', $this->program));
    }

    // §4.1 - a 2 h séance goes on a 2 h créneau, and the following séance moves on to the next one
    // (§4.2, one séance per créneau in automatic mode).
    public function testEachSeanceTakesItsOwnSlot(): void
    {
        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $first = $this->seance($sequence, 'Séance 1', 2.0, 0);
        $second = $this->seance($sequence, 'Séance 2', 2.0, 1);

        $this->service->replan($sequence->getProgression());

        self::assertSame($slots[0], $first->getActivePlacements()[0]->getLessonSession());
        self::assertSame($slots[1], $second->getActivePlacements()[0]->getLessonSession());
    }

    // §4.1 - overrunning the créneau by 45 min or less is still a fit, so no split.
    public function testSeanceOverrunningBy45MinutesStillFitsOneSlot(): void
    {
        $this->givenSlots([$this->slot('2026-09-01', '08:00', '10:00', 2.0)]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance longue', 2.75, 0);

        $this->service->replan($sequence->getProgression());

        self::assertCount(1, $seance->getActivePlacements());
    }

    // §4.1 again, on the other side of the boundary: 2 h 46 on a 2 h créneau no longer fits, so it
    // splits over consecutive créneaux (the design's "séance scindée sur 2 créneaux").
    public function testSeanceOverrunningByMoreThan45MinutesIsSplit(): void
    {
        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance très longue', 3.0, 0);

        $this->service->replan($sequence->getProgression());

        $placements = $seance->getActivePlacements();
        self::assertCount(2, $placements);
        self::assertSame('2.00', $placements[0]->getDuration());
        self::assertSame('1.00', $placements[1]->getDuration());
        self::assertSame(0, $placements[0]->getPartIndex());
        self::assertSame(1, $placements[1]->getPartIndex());
    }

    // §4.3 - a séance shorter than its créneau is placed anyway, and flagged.
    public function testSeanceShorterThanItsSlotIsPlacedAndFlagged(): void
    {
        $this->givenSlots([$this->slot('2026-09-01', '08:00', '10:00', 2.0)]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance courte', 1.0, 0);

        $this->service->replan($sequence->getProgression());

        self::assertCount(1, $seance->getActivePlacements());
        self::assertTrue($seance->isTooShort());
    }

    // §4.4 - a forced start date landing before the previous séquence had finished stops that one
    // there, frees the créneaux from that date on, and raises the flag.
    public function testForcedStartDateTruncatesThePreviousSequence(): void
    {
        $this->givenSlots([
            $this->slot('2026-09-01', '08:00', '10:00', 2.0),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0),
            $this->slot('2026-09-15', '08:00', '10:00', 2.0),
        ]);

        $progression = $this->progression();

        $first = $this->sequenceIn($progression, 0);
        $this->seance($first, 'A1', 2.0, 0);
        $this->seance($first, 'A2', 2.0, 1);
        $this->seance($first, 'A3', 2.0, 2);

        $second = $this->sequenceIn($progression, 1);
        $second->setForcedStartDate(new \DateTimeImmutable('2026-09-08'));
        $this->seance($second, 'B1', 2.0, 0);

        $this->service->replan($progression);

        self::assertTrue($first->isTruncatedByNext());
        self::assertSame(2.0, $first->getPlacedHours(), 'only the 1 sept. créneau stays with the first séquence');
        self::assertSame('2026-09-08', $second->getFirstPlacedDay()?->format('Y-m-d'));
    }

    // §4.5 - "Placer dans l'EDT" unchecked keeps the séquence in the progression without touching
    // a créneau, and the next séquence takes the slots it would have used.
    public function testSequenceNotPlacedInTimetableConsumesNoSlot(): void
    {
        $slots = [$this->slot('2026-09-01', '08:00', '10:00', 2.0)];
        $this->givenSlots($slots);

        $progression = $this->progression();

        $skipped = $this->sequenceIn($progression, 0);
        $skipped->setPlaceInTimetable(false);
        $skippedSeance = $this->seance($skipped, 'Non placée', 2.0, 0);

        $placed = $this->sequenceIn($progression, 1);
        $placedSeance = $this->seance($placed, 'Placée', 2.0, 0);

        $this->service->replan($progression);

        self::assertSame([], $skippedSeance->getActivePlacements());
        self::assertSame($slots[0], $placedSeance->getActivePlacements()[0]->getLessonSession());
    }

    // §4.6 - a forced date skips ahead and deliberately leaves the intermediate créneaux free.
    public function testForcedStartDateSkipsEarlierSlots(): void
    {
        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0),
            $this->slot('2026-09-15', '08:00', '10:00', 2.0),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $sequence->setForcedStartDate(new \DateTimeImmutable('2026-09-15'));
        $seance = $this->seance($sequence, 'Séance', 2.0, 0);

        $this->service->replan($sequence->getProgression());

        self::assertSame($slots[2], $seance->getActivePlacements()[0]->getLessonSession());
    }

    // §4.7 - moving a créneau in the timetable puts its séance back into "à réassocier", detected
    // from the placement's own snapshot rather than any listener.
    public function testMovingASlotMarksTheSeanceToReassociate(): void
    {
        $slot = $this->slot('2026-09-01', '08:00', '10:00', 2.0);
        $this->givenSlots([$slot]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance', 2.0, 0);
        $this->service->replan($sequence->getProgression());

        self::assertFalse($seance->needsReassociation());

        $slot->setDay(new \DateTimeImmutable('2026-09-02'));

        self::assertTrue($seance->needsReassociation());
    }

    // §4.9 - a per-group séance is reproduced once per group, one créneau each, each part naming
    // the Option it serves.
    public function testPerGroupSeanceTakesOneSlotPerOption(): void
    {
        $slam = $this->option('SLAM');
        $sisr = $this->option('SISR');

        $slots = [
            $this->slot('2026-09-01', '08:00', '09:30', 1.5, $slam),
            $this->slot('2026-09-02', '08:00', '09:30', 1.5, $sisr),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'TP forensic', 1.5, 0);
        $seance->setPerGroup(true);

        $this->service->replan($sequence->getProgression());

        $placements = $seance->getActivePlacements();
        self::assertCount(2, $placements);
        self::assertSame($slam, $placements[0]->getOption());
        self::assertSame($sisr, $placements[1]->getOption());
    }

    // §4.10 as scoped with the product owner: validating names the créneau and freezes the link,
    // and deliberately writes no lesson log (design/validated/lesson-log-cahier-de-texte.md).
    public function testValidateNamesTheSlotAndConfirmsThePlacement(): void
    {
        $slot = $this->slot('2026-09-01', '08:00', '10:00', 2.0);
        $this->givenSlots([$slot]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Panorama des incidents', 2.0, 0);
        $this->service->replan($sequence->getProgression());

        self::assertFalse($seance->getActivePlacements()[0]->isConfirmed());

        $this->service->validate($sequence);

        self::assertTrue($seance->getActivePlacements()[0]->isConfirmed());
        self::assertSame('Panorama des incidents', $slot->getTitle());
        self::assertSame($this->topic, $slot->getTopic());
    }

    // Validating also reconnects the library instance to the créneau, which is what keeps the
    // lesson log's "pré-remplir" reachable now that the program-side "planifier une séance" screen
    // is gone.
    public function testValidateLinksTheSeanceInstanceToItsSlot(): void
    {
        $slot = $this->slot('2026-09-01', '08:00', '10:00', 2.0);
        $this->givenSlots([$slot]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Panorama des incidents', 2.0, 0);
        $instance = new SeanceInstance($this->program, new User('teacher'));
        $seance->setSeanceInstance($instance);

        $this->service->replan($sequence->getProgression());
        $this->service->validate($sequence);

        self::assertSame($slot, $instance->getLessonSession());
    }

    // ...but only when there is a single créneau to name: the OneToOne is unique, so a split
    // séance has nothing to point at and deliberately keeps no link.
    public function testValidateLeavesASplitSeanceInstanceUnlinked(): void
    {
        $this->givenSlots([
            $this->slot('2026-09-01', '08:00', '10:00', 2.0),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0),
        ]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance très longue', 3.0, 0);
        $instance = new SeanceInstance($this->program, new User('teacher'));
        $seance->setSeanceInstance($instance);

        $this->service->replan($sequence->getProgression());
        $this->service->validate($sequence);

        self::assertCount(2, $seance->getActivePlacements());
        self::assertNull($instance->getLessonSession());
    }

    // Replanning must be idempotent - every screen calls it after any move, so a second run on
    // unchanged input has to produce the exact same layout rather than stack placements up.
    public function testReplanIsIdempotent(): void
    {
        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance', 2.0, 0);

        $this->service->replan($sequence->getProgression());
        $this->service->replan($sequence->getProgression());

        self::assertCount(1, $seance->getActivePlacements());
        self::assertSame($slots[0], $seance->getActivePlacements()[0]->getLessonSession());
    }

    // Once validated, a placement survives a replan untouched - reordering later séquences must
    // never silently move a lesson the teacher already committed to.
    public function testConfirmedPlacementsSurviveAReplan(): void
    {
        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance', 2.0, 0);

        $this->service->replan($sequence->getProgression());
        $this->service->validate($sequence);
        $this->service->replan($sequence->getProgression());

        self::assertSame($slots[0], $seance->getActivePlacements()[0]->getLessonSession());
        self::assertTrue($seance->getActivePlacements()[0]->isConfirmed());
    }

    // The 2b picker's "Scinder" branch: the content is spread over the picked créneaux in date
    // order, each part capped by what its créneau offers.
    public function testManualSplitAssociationSpreadsTheContentInDateOrder(): void
    {
        $later = $this->slot('2026-09-24', '08:00', '09:00', 1.0);
        $earlier = $this->slot('2026-09-10', '08:00', '09:00', 1.0);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Analyse de logs', 2.0, 0);

        $this->service->associate($seance, [$later, $earlier], 'split', null);

        $placements = $seance->getActivePlacements();
        self::assertCount(2, $placements);
        self::assertSame($earlier, $placements[0]->getLessonSession());
        self::assertSame($later, $placements[1]->getLessonSession());
        self::assertSame('1.00', $placements[0]->getDuration());
        self::assertSame('1.00', $placements[1]->getDuration());
    }

    // "Ou : ramener la séance à 1 h (durée du créneau)" - the séance's own planned duration comes
    // down to the créneau's, which by construction un-splits it.
    public function testFitToSlotBringsThePlannedDurationDown(): void
    {
        $slot = $this->slot('2026-09-01', '08:00', '09:00', 1.0);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Analyse de logs', 2.0, 0);

        $this->service->fitToSlot($seance, $slot);

        self::assertSame('1.00', $seance->getPlannedDuration());
        self::assertCount(1, $seance->getActivePlacements());
    }

    /** @param list<LessonSession> $slots */
    private function givenSlots(array $slots): void
    {
        $this->lessonSessionRepository->method('findOrderedForTopic')->willReturn($slots);
    }

    private function slot(string $day, string $start, string $end, float $length, ?Option $option = null): LessonSession
    {
        $session = new LessonSession($this->program);
        $session->setDay(new \DateTimeImmutable($day));
        $session->setStartHour(new \DateTimeImmutable($day.' '.$start));
        $session->setEndHour(new \DateTimeImmutable($day.' '.$end));
        $session->setLength(number_format($length, 2, '.', ''));
        $session->setTopic($this->topic);

        if (null !== $option) {
            $session->addOption($option);
        }

        // LessonSession::$id is generated by Doctrine, but the placement service keys its
        // "créneau already taken" map on it - so hand out stable ids the way a persisted row would.
        $this->setId($session, $this->nextSessionId++);

        return $session;
    }

    private function option(string $shortName): Option
    {
        $option = new Option($shortName, $shortName, '#1B6BA8');
        $this->setId($option, crc32($shortName));

        return $option;
    }

    private function progression(): Progression
    {
        return new Progression($this->topic, new User('teacher'));
    }

    private function sequence(): ProgressionSequence
    {
        return $this->sequenceIn($this->progression(), 0);
    }

    private function sequenceIn(Progression $progression, int $position): ProgressionSequence
    {
        $instance = new SequenceInstance($this->program, new User('teacher'));
        $instance->setTitre('Séquence '.($position + 1));

        $sequence = new ProgressionSequence($progression, $instance);
        $sequence->setPosition($position);
        $this->setId($sequence, $position + 1);

        return $sequence;
    }

    private function seance(ProgressionSequence $sequence, string $title, float $duration, int $position): ProgressionSeance
    {
        $seance = new ProgressionSeance($sequence, $title);
        $seance->setPlannedDuration(number_format($duration, 2, '.', ''));
        $seance->setPosition($position);

        return $seance;
    }

    // $id is private and declared on the entity itself for most of them, but on the
    // AbstractStructureNode parent for Option/Cohort/Track - so walk up until it's found.
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

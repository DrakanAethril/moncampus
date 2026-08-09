<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\LessonSession;
use App\Entity\Option;
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
        $first = $this->seance($sequence, 'Séance 1', 120, 0);
        $second = $this->seance($sequence, 'Séance 2', 120, 1);

        $this->service->replan($sequence->getProgression());

        self::assertSame($slots[0], $first->getActivePlacements()[0]->getLessonSession());
        self::assertSame($slots[1], $second->getActivePlacements()[0]->getLessonSession());
    }

    // The regression this suite exists to keep out: a séance's duration is a MINUTE count, a
    // créneau's LessonSession::$length is decimal HOURS. Read as hours, a perfectly ordinary
    // 55-minute séance became a 55-hour one - it fit no créneau, split itself over the class's
    // whole year and made every "X h placées / Y h" total nonsense.
    public function testA55MinuteSeanceIsNotReadAs55Hours(): void
    {
        $slots = [
            $this->slot('2026-09-01', '08:00', '09:00', 1.0),
            $this->slot('2026-09-08', '08:00', '09:00', 1.0),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance de 55 min', 55, 0);

        $this->service->replan($sequence->getProgression());

        $placements = $seance->getActivePlacements();
        self::assertCount(1, $placements, 'a 55-min séance fits inside a 1 h créneau');
        self::assertSame($slots[0], $placements[0]->getLessonSession());
        self::assertSame(55, $placements[0]->getDurationMinutes());
        self::assertSame(55, $sequence->getPlannedMinutes());
        self::assertFalse($seance->isTooShort(), '5 min short of a 1 h créneau is inside the 15% tolerance');
    }

    // §4.1 - overrunning the créneau by 45 min or less is still a fit, so no split.
    public function testSeanceOverrunningBy45MinutesStillFitsOneSlot(): void
    {
        $this->givenSlots([$this->slot('2026-09-01', '08:00', '10:00', 2.0)]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance longue', 165, 0);

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
        $seance = $this->seance($sequence, 'Séance très longue', 180, 0);

        $this->service->replan($sequence->getProgression());

        $placements = $seance->getActivePlacements();
        self::assertCount(2, $placements);
        self::assertSame(120, $placements[0]->getDurationMinutes());
        self::assertSame(60, $placements[1]->getDurationMinutes());
        self::assertSame(0, $placements[0]->getPartIndex());
        self::assertSame(1, $placements[1]->getPartIndex());
    }

    // §4.3 - a séance shorter than its créneau is placed anyway, and flagged.
    public function testSeanceShorterThanItsSlotIsPlacedAndFlagged(): void
    {
        $this->givenSlots([$this->slot('2026-09-01', '08:00', '10:00', 2.0)]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance courte', 60, 0);

        $this->service->replan($sequence->getProgression());

        self::assertCount(1, $seance->getActivePlacements());
        self::assertTrue($seance->isTooShort());
    }

    // ...but only past the 15% tolerance: an hour's séance in a 1 h 10 créneau is the same lesson,
    // not a gap. Reported from production, where every ordinary 55-min-in-1-h row carried the
    // "séance plus courte que le créneau" flag until it meant nothing.
    public function testSeanceWithinTheToleranceOfItsSlotIsNotFlagged(): void
    {
        // 10 min short of 70 = 14.3%, which the tolerance has to cover: this exact case WAS flagged
        // back when it stood at 10%, so it pins the current value rather than just staying green
        // whatever it is.
        $this->givenSlots([$this->slot('2026-09-01', '08:00', '09:10', 70 / 60)]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance', 60, 0);

        $this->service->replan($sequence->getProgression());

        self::assertCount(1, $seance->getActivePlacements());
        self::assertFalse($seance->isTooShort());
    }

    // The 2b picker's "= créneau" taken literally: the séance is committed for the créneau's own
    // length, so there is nothing left to flag. It used to fall back to the séance's planned
    // duration, which meant answering "= créneau" still reported the séance as too short.
    public function testAssociatingWithSlotDurationCommitsTheWholeSlot(): void
    {
        $slot = $this->slot('2026-09-01', '08:00', '10:00', 2.0);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance courte', 30, 0);

        $this->service->associate($seance, [$slot], 'duplicate', null);

        $placements = $seance->getActivePlacements();
        self::assertCount(1, $placements);
        self::assertSame(120, $placements[0]->getDurationMinutes());
        self::assertFalse($seance->isTooShort());
    }

    // Picking an explicit duty must be able to clear the flag too - the check now measures what was
    // committed to the créneau, not the séquence's theoretical duration.
    public function testExplicitDutyMatchingTheSlotClearsTheTooShortFlag(): void
    {
        $slot = $this->slot('2026-09-01', '08:00', '09:00', 1.0);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance de 55 min', 55, 0);

        $this->service->associate($seance, [$slot], 'duplicate', 60);

        self::assertSame(60, $seance->getActivePlacements()[0]->getDurationMinutes());
        self::assertFalse($seance->isTooShort());
    }

    // The tolerance must not swallow a genuine gap: half a créneau is still half a créneau.
    public function testAssociatingAGenuinelyShortDutyStillFlags(): void
    {
        $slot = $this->slot('2026-09-01', '08:00', '10:00', 2.0);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance courte', 60, 0);

        $this->service->associate($seance, [$slot], 'split', null);

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
        $this->seance($first, 'A1', 120, 0);
        $this->seance($first, 'A2', 120, 1);
        $this->seance($first, 'A3', 120, 2);

        $second = $this->sequenceIn($progression, 1);
        $second->setForcedStartDate(new \DateTimeImmutable('2026-09-08'));
        $this->seance($second, 'B1', 120, 0);

        $this->service->replan($progression);

        self::assertTrue($first->isTruncatedByNext());
        self::assertSame(120, $first->getPlacedMinutes(), 'only the 1 sept. créneau stays with the first séquence');
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
        $skippedSeance = $this->seance($skipped, 'Non placée', 120, 0);

        $placed = $this->sequenceIn($progression, 1);
        $placedSeance = $this->seance($placed, 'Placée', 120, 0);

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
        $seance = $this->seance($sequence, 'Séance', 120, 0);

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
        $seance = $this->seance($sequence, 'Séance', 120, 0);
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
        $seance = $this->seance($sequence, 'TP forensic', 90, 0);
        $seance->setPerGroup(true);

        $this->service->replan($sequence->getProgression());

        $placements = $seance->getActivePlacements();
        self::assertCount(2, $placements);
        self::assertSame($slam, $placements[0]->getOption());
        self::assertSame($sisr, $placements[1]->getOption());
    }

    // Once every group has had its créneau the class is whole again, so the next whole-class
    // créneau belongs to the NEXT séance - it must not become a third copy of the same one.
    // Reported from production: a séance split over 2 group créneaux also landed on the whole-class
    // créneau that followed.
    public function testAPerGroupSeanceStopsBeforeTheFollowingWholeClassSlot(): void
    {
        $slam = $this->option('SLAM');
        $sisr = $this->option('SISR');

        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0, $slam),
            $this->slot('2026-09-02', '08:00', '10:00', 2.0, $sisr),
            $this->slot('2026-09-03', '08:00', '10:00', 2.0),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $first = $this->seance($sequence, 'TP par groupe', 120, 0);
        $second = $this->seance($sequence, 'Cours en classe entière', 120, 1);

        $this->service->replan($sequence->getProgression());

        self::assertCount(2, $first->getActivePlacements(), 'one créneau per group, and nothing more');
        self::assertSame($slots[0], $first->getActivePlacements()[0]->getLessonSession());
        self::assertSame($slots[1], $first->getActivePlacements()[1]->getLessonSession());

        // The whole-class créneau is left for the séance that actually comes next.
        self::assertCount(1, $second->getActivePlacements());
        self::assertSame($slots[2], $second->getActivePlacements()[0]->getLessonSession());
    }

    // ...and the séance does NOT have to be flagged "par groupe" by hand first: a créneau that does
    // not hold the whole class is recognised as such from the timetable. Reported from production,
    // where the group créneaux were instead handed to the FOLLOWING séances - group A got séance 1,
    // group B got séance 2 - so each half of the class saw half the séquence, out of step.
    public function testAGroupSlotIsDetectedWithoutFlaggingTheSeanceByHand(): void
    {
        $slam = $this->option('SLAM');
        $sisr = $this->option('SISR');

        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0, $slam),
            $this->slot('2026-09-02', '08:00', '10:00', 2.0, $sisr),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0, $slam),
            $this->slot('2026-09-09', '08:00', '10:00', 2.0, $sisr),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $first = $this->seance($sequence, 'Séance 1', 120, 0);
        $second = $this->seance($sequence, 'Séance 2', 120, 1);

        $this->service->replan($sequence->getProgression());

        // Séance 1 to BOTH groups in week 1...
        self::assertTrue($first->isPerGroup());
        self::assertCount(2, $first->getActivePlacements());
        self::assertSame($slots[0], $first->getActivePlacements()[0]->getLessonSession());
        self::assertSame($slots[1], $first->getActivePlacements()[1]->getLessonSession());

        // ...and séance 2 to both groups in week 2, rather than stealing group B's week-1 créneau.
        self::assertTrue($second->isPerGroup());
        self::assertCount(2, $second->getActivePlacements());
        self::assertSame($slots[2], $second->getActivePlacements()[0]->getLessonSession());
        self::assertSame($slots[3], $second->getActivePlacements()[1]->getLessonSession());
    }

    // "La classe n'est pas complète" is at least ONE Option, not exactly one: a créneau shared by
    // two of the three groups is still partial, and the third group has to get the séance too.
    public function testACreneauSharedByTwoOptionsStillLeavesTheThirdGroupToServe(): void
    {
        $slam = $this->option('SLAM');
        $sisr = $this->option('SISR');
        $ais = $this->option('AIS');

        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0, $slam, $sisr),
            $this->slot('2026-09-02', '08:00', '10:00', 2.0, $ais),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0, $slam, $sisr),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'TP', 120, 0);

        $this->service->replan($sequence->getProgression());

        $placements = $seance->getActivePlacements();
        self::assertCount(2, $placements);
        self::assertSame($slots[0], $placements[0]->getLessonSession());
        self::assertSame($slots[1], $placements[1]->getLessonSession());
        // A créneau serving two Options has no single group to name against its part.
        self::assertNull($placements[0]->getOption());
        self::assertSame($ais, $placements[1]->getOption());
    }

    // The whole-class case must not become "par groupe" by accident: no Option, no duplication.
    public function testWholeClassSlotsAreNeverDuplicated(): void
    {
        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $first = $this->seance($sequence, 'Séance 1', 120, 0);
        $second = $this->seance($sequence, 'Séance 2', 120, 1);

        $this->service->replan($sequence->getProgression());

        self::assertFalse($first->isPerGroup());
        self::assertCount(1, $first->getActivePlacements());
        self::assertSame($slots[1], $second->getActivePlacements()[0]->getLessonSession());
    }

    // A séance too long for a group créneau keeps the ordinary split path rather than silently
    // placing nothing - §4.1 still wins over §4.9 on the "does it fit" question.
    public function testASeanceTooLongForAGroupSlotIsStillSplit(): void
    {
        $slam = $this->option('SLAM');

        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0, $slam),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0, $slam),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance très longue', 240, 0);

        $this->service->replan($sequence->getProgression());

        self::assertCount(2, $seance->getActivePlacements());
        self::assertFalse($seance->isPerGroup());
    }

    // §4.10 as scoped with the product owner: validating freezes the link and puts the
    // progression's matière on the créneau, and deliberately writes no lesson log
    // (design/validated/lesson-log-cahier-de-texte.md).
    //
    // It pointedly does NOT name the créneau. LessonSession::getDisplayName() prefers the title
    // over the matière, so writing the séance's name there made the timetable announce the séance
    // everywhere a créneau shows up - web calendar, mobile app, attendance sheets. Fixed in 2ce076b
    // (with a migration giving already-renamed créneaux their matière back); the title assertion
    // below is what stops it coming back.
    public function testValidateConfirmsThePlacementAndSetsTheTopicWithoutNamingTheSlot(): void
    {
        $slot = $this->slot('2026-09-01', '08:00', '10:00', 2.0);
        $this->givenSlots([$slot]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Panorama des incidents', 120, 0);
        $this->service->replan($sequence->getProgression());

        self::assertFalse($seance->getActivePlacements()[0]->isConfirmed());

        $this->service->validate($sequence);

        self::assertTrue($seance->getActivePlacements()[0]->isConfirmed());
        self::assertSame($this->topic, $slot->getTopic());
        self::assertNull($slot->getTitle(), 'a timetable slot announces its matière, never the séance placed on it');
    }

    // Validating also reconnects the library instance to the créneau, which is what keeps the
    // lesson log's "pré-remplir" reachable now that the program-side "planifier une séance" screen
    // is gone.
    public function testValidateLinksTheSeanceInstanceToItsSlot(): void
    {
        $slot = $this->slot('2026-09-01', '08:00', '10:00', 2.0);
        $this->givenSlots([$slot]);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Panorama des incidents', 120, 0);
        $instance = new SeanceInstance($this->program, new User('teacher'));
        $seance->setSeanceInstance($instance);

        $this->service->replan($sequence->getProgression());
        $this->service->validate($sequence);

        self::assertSame($slot, $instance->getLessonSession());
    }

    // A séance occupying SEVERAL créneaux - split, or duplicated once per group - is linked to its
    // FIRST one. The OneToOne is unique so it can name only that one, but the séance is genuinely
    // scheduled and has to count as such on the Program-side "x / y programmées" column; leaving it
    // unlinked reported it as not scheduled at all. The other créneaux reach the same content
    // through App\Service\SeanceContentResolver instead.
    public function testValidateLinksAMultiSlotSeanceToItsFirstSlot(): void
    {
        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0),
            $this->slot('2026-09-08', '08:00', '10:00', 2.0),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Séance très longue', 180, 0);
        $instance = new SeanceInstance($this->program, new User('teacher'));
        $seance->setSeanceInstance($instance);

        $this->service->replan($sequence->getProgression());
        $this->service->validate($sequence);

        self::assertCount(2, $seance->getActivePlacements());
        self::assertSame($slots[0], $instance->getLessonSession());
    }

    // Same for the per-group case, which is the one actually reported: two groups, one créneau
    // each, and the séance was showing up as "non programmée".
    public function testValidateLinksAPerGroupSeanceToItsFirstSlot(): void
    {
        $slam = $this->option('SLAM');
        $sisr = $this->option('SISR');

        $slots = [
            $this->slot('2026-09-01', '08:00', '10:00', 2.0, $slam),
            $this->slot('2026-09-02', '08:00', '10:00', 2.0, $sisr),
        ];
        $this->givenSlots($slots);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'TP par groupe', 120, 0);
        $instance = new SeanceInstance($this->program, new User('teacher'));
        $seance->setSeanceInstance($instance);

        $this->service->replan($sequence->getProgression());
        $this->service->validate($sequence);

        self::assertTrue($seance->isPerGroup());
        self::assertCount(2, $seance->getActivePlacements());
        self::assertSame($slots[0], $instance->getLessonSession());
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
        $seance = $this->seance($sequence, 'Séance', 120, 0);

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
        $seance = $this->seance($sequence, 'Séance', 120, 0);

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
        $seance = $this->seance($sequence, 'Analyse de logs', 120, 0);

        $this->service->associate($seance, [$later, $earlier], 'split', null);

        $placements = $seance->getActivePlacements();
        self::assertCount(2, $placements);
        self::assertSame($earlier, $placements[0]->getLessonSession());
        self::assertSame($later, $placements[1]->getLessonSession());
        self::assertSame(60, $placements[0]->getDurationMinutes());
        self::assertSame(60, $placements[1]->getDurationMinutes());
    }

    // "Ou : ramener la séance à 1 h (durée du créneau)" - the séance's own planned duration comes
    // down to the créneau's, which by construction un-splits it.
    public function testFitToSlotBringsThePlannedDurationDown(): void
    {
        $slot = $this->slot('2026-09-01', '08:00', '09:00', 1.0);

        $sequence = $this->sequence();
        $seance = $this->seance($sequence, 'Analyse de logs', 120, 0);

        $this->service->fitToSlot($seance, $slot);

        self::assertSame(60, $seance->getPlannedMinutes());
        self::assertCount(1, $seance->getActivePlacements());
    }

    /** @param list<LessonSession> $slots */
    private function givenSlots(array $slots): void
    {
        $this->lessonSessionRepository->method('findOrderedForTopic')->willReturn($slots);
    }

    private function slot(string $day, string $start, string $end, float $length, Option ...$options): LessonSession
    {
        $session = new LessonSession($this->program);
        $session->setDay(new \DateTimeImmutable($day));
        $session->setStartHour(new \DateTimeImmutable($day.' '.$start));
        $session->setEndHour(new \DateTimeImmutable($day.' '.$end));
        $session->setLength(number_format($length, 2, '.', ''));
        $session->setTopic($this->topic);

        foreach ($options as $option) {
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

    // $minutes, not hours: séance durations are authored in minutes throughout the app
    // (SeanceTemplate::$duree), unlike a créneau's LessonSession::$length just above, which really
    // is a decimal hour count. Keeping the two helpers in their own real units is deliberate - it
    // is the mismatch between them that this suite has to pin down.
    private function seance(ProgressionSequence $sequence, string $title, int $minutes, int $position): ProgressionSeance
    {
        $seance = new ProgressionSeance($sequence, $title);
        $seance->setPlannedMinutes($minutes);
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

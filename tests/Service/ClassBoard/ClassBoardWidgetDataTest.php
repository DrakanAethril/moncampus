<?php

declare(strict_types=1);

namespace App\Tests\Service\ClassBoard;

use App\Entity\Assignment;
use App\Entity\ClassBoard;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AssignmentNature;
use App\Repository\AssignmentRepository;
use App\Repository\FileLibraryNodeRepository;
use App\Repository\GroupBatchRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\RandomDrawRepository;
use App\Repository\SeanceInstanceRepository;
use App\Repository\SeanceTemplateRepository;
use App\Repository\SequenceTemplateRepository;
use App\Security\FeatureAccess;
use App\Security\LessonLogEditors;
use App\Security\ProgramTimetableAccess;
use App\Security\StructureAccessChecker;
use App\Service\ClassBoard\ClassBoardLiveData;
use App\Service\ClassBoard\ClassBoardWidgetData;
use App\Service\ClassBoard\TodaySlots;
use App\Service\ClassBoard\VideoEmbed;
use App\Service\FileUploadService;
use App\Service\HtmlPlainText;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the board reads of the class (design/validated/tableau-virtuel.md, §6): which slot of the
 * day it shows, the one conversion between the timetable's hours and the séance's minutes, and the
 * rule that « Travail à faire » lists the owner's own work and nothing else.
 */
class ClassBoardWidgetDataTest extends TestCase
{
    public function testTheSlotUnderWayIsTheOneShown(): void
    {
        $morning = $this->slot('08:00', '10:00');
        $afternoon = $this->slot('14:00', '16:00');

        self::assertSame($afternoon, TodaySlots::current([$morning, $afternoon], new \DateTimeImmutable('2026-10-01 15:10')));
    }

    public function testBetweenTwoSlotsTheNextOneIsShown(): void
    {
        $morning = $this->slot('08:00', '10:00');
        $afternoon = $this->slot('14:00', '16:00');

        self::assertSame($afternoon, TodaySlots::current([$afternoon, $morning], new \DateTimeImmutable('2026-10-01 11:30')));
        self::assertSame($morning, TodaySlots::current([$afternoon, $morning], new \DateTimeImmutable('2026-10-01 07:00')));
    }

    /** A board opened after the last lesson still shows that lesson, rather than nothing. */
    public function testAfterTheDayTheLastSlotIsShown(): void
    {
        $morning = $this->slot('08:00', '10:00');
        $afternoon = $this->slot('14:00', '16:00');

        self::assertSame($afternoon, TodaySlots::current([$morning, $afternoon], new \DateTimeImmutable('2026-10-01 19:00')));
        self::assertNull(TodaySlots::current([], new \DateTimeImmutable('2026-10-01 19:00')));
    }

    /** Slots count in decimal HOURS, phases in MINUTES - converted once, here. */
    public function testHoursBecomeMinutesOnce(): void
    {
        self::assertSame(90, TodaySlots::minutes($this->slot('08:00', '09:30', '1.50')));
        self::assertSame(120, TodaySlots::minutes($this->slot('08:00', '10:00', '2.00')));
        self::assertSame(45, TodaySlots::phaseMinutes('45.00'));
        self::assertSame(1, TodaySlots::phaseMinutes('0.20'));
        self::assertSame(1, TodaySlots::phaseMinutes(null));
    }

    public function testTheWorkWidgetListsOnlyTheOwnersVisibleUpcomingWork(): void
    {
        $owner = new User('owner');
        $colleague = new User('colleague');
        $program = $this->createStub(Program::class);
        $now = new \DateTimeImmutable('2026-10-01 10:00');

        $assignments = [
            $this->assignment($program, $owner, 'Le mien', '2026-10-05', '2026-09-30'),
            $this->assignment($program, $colleague, 'Celui d’un collègue', '2026-10-03', '2026-09-30'),
            $this->assignment($program, $owner, 'Pas encore visible', '2026-10-04', '2026-10-02'),
            $this->assignment($program, $owner, 'Déjà passé', '2026-09-28', '2026-09-20'),
            $this->assignment($program, $owner, 'Le mien, plus tôt', '2026-10-02', '2026-09-29'),
        ];

        $repository = $this->createStub(AssignmentRepository::class);
        $repository->method('findForPrograms')->willReturn($assignments);

        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isProgramTeacher')->willReturn(true);

        $data = $this->widgetData($repository, $checker, $now)->forWidget(
            new ClassBoard($owner, 'Tableau', $program),
            ['id' => 'w1', 'type' => 'work', 'config' => ['rows' => 5]],
        );

        self::assertSame('ok', $data['state']);
        self::assertIsArray($data['items']);
        self::assertSame(['Le mien, plus tôt', 'Le mien'], array_column($data['items'], 'title'));
    }

    /**
     * The groups widget draws with the group creation tool's panel, so it is handed what that panel
     * works from; a saved lot is only what it opens on, and one that is gone says so.
     */
    public function testTheGroupsWidgetIsHandedTheClassAndNeedsNoLot(): void
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isProgramTeacher')->willReturn(true);
        $data = $this->widgetData($this->createStub(AssignmentRepository::class), $checker, new \DateTimeImmutable());
        $program = $this->createStub(Program::class);
        $program->method('getStudents')->willReturn(new ArrayCollection());
        $program->method('getOptions')->willReturn(new ArrayCollection());
        $board = new ClassBoard(new User('owner'), 'Tableau', $program);

        $free = $data->forWidget($board, ['id' => 'w1', 'type' => 'groups', 'config' => ['batchId' => null]]);
        self::assertSame(ClassBoardWidgetData::OK, $free['state']);
        self::assertNull($free['lot']);
        self::assertSame([], $free['students']);
        self::assertSame([], $free['options']);

        $gone = $data->forWidget($board, ['id' => 'w1', 'type' => 'groups', 'config' => ['batchId' => 9]]);
        self::assertSame(ClassBoardWidgetData::SOURCE_MISSING, $gone['state']);
    }

    public function testAClassWidgetOfABoardWhoseClassIsNoLongerTaughtSaysSo(): void
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isProgramTeacher')->willReturn(false);

        $data = $this->widgetData($this->createStub(AssignmentRepository::class), $checker, new \DateTimeImmutable())->forWidget(
            new ClassBoard(new User('owner'), 'Tableau', $this->createStub(Program::class)),
            ['id' => 'w1', 'type' => 'work', 'config' => []],
        );

        self::assertSame(ClassBoardWidgetData::NOT_TEACHING, $data['state']);
    }

    private function widgetData(AssignmentRepository $assignments, StructureAccessChecker $checker, \DateTimeImmutable $now): ClassBoardWidgetData
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $translator->method('getLocale')->willReturn('fr');

        return new ClassBoardWidgetData(
            $checker,
            $this->createStub(RandomDrawRepository::class),
            $this->createStub(GroupBatchRepository::class),
            $this->createStub(ProgramStudentOptionRepository::class),
            $this->createStub(LessonSessionRepository::class),
            $this->createStub(SeanceInstanceRepository::class),
            $this->createStub(SeanceTemplateRepository::class),
            $this->createStub(SequenceTemplateRepository::class),
            $assignments,
            $this->createStub(FileLibraryNodeRepository::class),
            $this->createStub(LessonLogEditors::class),
            $this->createStub(ProgramTimetableAccess::class),
            $this->createStub(FeatureAccess::class),
            $this->createStub(AuthorizationCheckerInterface::class),
            $this->createStub(FileUploadService::class),
            $this->createStub(HtmlPlainText::class),
            new VideoEmbed(),
            $translator,
            new MockClock($now),
            $this->createStub(ClassBoardLiveData::class),
        );
    }

    private function slot(string $start, string $end, string $length = '2.00'): LessonSession
    {
        $slot = new LessonSession($this->createStub(Program::class));
        $slot->setDay(new \DateTimeImmutable('2026-10-01'))
            ->setStartHour(new \DateTimeImmutable('1970-01-01 '.$start))
            ->setEndHour(new \DateTimeImmutable('1970-01-01 '.$end))
            ->setLength($length);

        return $slot;
    }

    private function assignment(Program $program, User $author, string $title, string $due, ?string $visibleAt): Assignment
    {
        $assignment = new Assignment($program);
        $assignment->setTitle($title)
            ->setDueDate(new \DateTimeImmutable($due.' 23:59'))
            ->setVisibleAt(null === $visibleAt ? null : new \DateTimeImmutable($visibleAt))
            ->setNature(AssignmentNature::ToSubmit);
        $assignment->setCreatedBy($author);

        return $assignment;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Entity\Cohort;
use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Security\FeatureAccess;
use App\Service\Calendar\IcsWriter;
use App\Service\Calendar\TimetableIcsBuilder;
use App\Service\LessonSessionEventFormatter;
use App\Service\NameColorGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * What the subscription link promises: it carries exactly what the calendar was showing when it was
 * taken. The filter travels as legend keys - the same vocabulary the swatches use - so these tests
 * are really about the one thing that could silently drift, which is the two sides computing that
 * key differently (see LessonSessionEventFormatter::legendKey()).
 */
class TimetableIcsBuilderTest extends TestCase
{
    private TimetableIcsBuilder $builder;

    protected function setUp(): void
    {
        // legendKey() reads nothing but the séance itself; the formatter's four collaborators are
        // only needed by format(), which this builder never calls.
        $formatter = new LessonSessionEventFormatter(
            $this->createStub(UrlGeneratorInterface::class),
            new NameColorGenerator(),
            $this->createStub(FeatureAccess::class),
            $this->createStub(AuthorizationCheckerInterface::class),
        );

        $this->builder = new TimetableIcsBuilder(new IcsWriter(), $formatter);
    }

    public function testItWritesOneEventPerSeance(): void
    {
        $ics = $this->build([$this->session('Maths'), $this->session('Anglais')], hidden: []);

        self::assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        self::assertStringContainsString('SUMMARY:Maths', $ics);
        self::assertStringContainsString('SUMMARY:Anglais', $ics);
    }

    public function testAHiddenOptionDropsItsSeancesFromTheFeed(): void
    {
        $slam = $this->option(7);
        $sisr = $this->option(9);

        $ics = $this->build(
            [$this->session('SLAM', [$slam]), $this->session('SISR', [$sisr])],
            hidden: ['7'],
        );

        self::assertStringNotContainsString('SUMMARY:SLAM', $ics);
        self::assertStringContainsString('SUMMARY:SISR', $ics);
    }

    /**
     * A séance with no Option, or with several, is « Classe Entière » on the legend and carries the
     * `default` key rather than an id - so hiding that swatch has to drop it too, and hiding an
     * option must not.
     */
    public function testTheDefaultSwatchCoversSeancesWithNoneOrSeveralOptions(): void
    {
        $sessions = [
            $this->session('Tronc commun'),
            $this->session('Mixte', [$this->option(7), $this->option(9)]),
            $this->session('SLAM', [$this->option(7)]),
        ];

        $ics = $this->build($sessions, hidden: ['default']);

        self::assertStringNotContainsString('SUMMARY:Tronc commun', $ics);
        self::assertStringNotContainsString('SUMMARY:Mixte', $ics);
        self::assertStringContainsString('SUMMARY:SLAM', $ics);
    }

    // On a teacher's personal calendar the swatches are formations, not options - the same filter
    // parameter, read in the other vocabulary.
    public function testOnTheTeacherCalendarTheKeysAreFormations(): void
    {
        $sessions = [
            $this->session('Maths', program: $this->program(3, 'SIO1')),
            $this->session('Anglais', program: $this->program(4, 'SIO2')),
        ];

        $ics = $this->builder->build('Emploi du temps', $sessions, ['3'], legendByProgram: true, uidDomain: 'moncampus.test');

        self::assertStringNotContainsString('SUMMARY:Maths', $ics);
        self::assertStringContainsString('SUMMARY:Anglais', $ics);
    }

    // A filter naming nothing that exists removes nothing - a stale link keeps working rather than
    // quietly emptying the subscriber's agenda.
    public function testAnUnknownLegendKeyRemovesNothing(): void
    {
        $ics = $this->build([$this->session('Maths')], hidden: ['4242']);

        self::assertStringContainsString('SUMMARY:Maths', $ics);
    }

    /**
     * The UID is what makes a moved séance *replace* its old copy in the subscriber's agenda instead
     * of appearing twice next to it, so it has to be the séance's own id and nothing else.
     */
    public function testTheUidIsTheSeanceIdAndSurvivesEverythingElseChanging(): void
    {
        $session = $this->session('Maths');
        $moved = $this->session('Maths renommé');
        $moved->setDay(new \DateTimeImmutable('2026-01-19'));

        self::assertStringContainsString('UID:lesson-session-42@moncampus.test', $this->build([$session], hidden: []));
        self::assertStringContainsString('UID:lesson-session-42@moncampus.test', $this->build([$moved], hidden: []));
    }

    public function testTheSeanceDayAndHoursAreReadInParisTime(): void
    {
        $ics = $this->build([$this->session('Maths')], hidden: []);

        // 12 January 2026, 08:00-10:00 in Paris (winter, +01:00).
        self::assertStringContainsString('DTSTART:20260112T070000Z', $ics);
        self::assertStringContainsString('DTEND:20260112T090000Z', $ics);
    }

    /**
     * @param list<LessonSession> $sessions
     * @param list<string>        $hidden
     */
    private function build(array $sessions, array $hidden): string
    {
        return $this->builder->build('BTS SIO 1', $sessions, $hidden, legendByProgram: false, uidDomain: 'moncampus.test');
    }

    /** @param list<Option> $options */
    private function session(string $title, array $options = [], ?Program $program = null): LessonSession
    {
        $session = new LessonSession($program ?? $this->program(3, 'SIO1'));
        $session->setTitle($title);
        $session->setDay(new \DateTimeImmutable('2026-01-12'));
        $session->setStartHour(new \DateTimeImmutable('2026-01-12 08:00'));
        $session->setEndHour(new \DateTimeImmutable('2026-01-12 10:00'));

        foreach ($options as $option) {
            $session->addOption($option);
        }

        $this->setId($session, 42);

        return $session;
    }

    private function option(int $id): Option
    {
        $option = new Option(\sprintf('Option %d', $id), \sprintf('OPT%d', $id), '#123456');
        $this->setId($option, $id);

        return $option;
    }

    private function program(int $id, string $shortName): Program
    {
        $program = new Program(
            \sprintf('%s 2026-2027', $shortName),
            $shortName,
            $this->createStub(Cohort::class),
            $this->createStub(SchoolYear::class),
        );
        $this->setId($program, $id);

        return $program;
    }

    // Doctrine owns the id; a unit test has to put one there by hand, and the whole point of these
    // tests is that the legend keys and the UID are built from ids. Walks up the hierarchy because
    // Option declares none of its own - it inherits it from App\Entity\AbstractStructureNode.
    private function setId(object $entity, int $id): void
    {
        for ($class = new \ReflectionClass($entity); false !== $class; $class = $class->getParentClass()) {
            if ($class->hasProperty('id')) {
                $class->getProperty('id')->setValue($entity, $id);

                return;
            }
        }

        self::fail(\sprintf('%s declares no id property', $entity::class));
    }
}

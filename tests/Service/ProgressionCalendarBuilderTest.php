<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSeancePlacement;
use App\Entity\ProgressionSequence;
use App\Entity\SchoolYear;
use App\Entity\SeanceInstance;
use App\Entity\SeanceTemplate;
use App\Entity\Section;
use App\Entity\SequenceInstance;
use App\Entity\SequenceTemplate;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\EvaluationRepository;
use App\Repository\ProgressionRepository;
use App\Service\ProgressionCalendarBuilder;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The read-only calendars of screens 4a/4b. What is pinned here is the CARD CONTRACT rather than
 * the layout: the ids a card has to carry for its title to link to the fiche séquence, and the fact
 * that 4a's collapse to one card per séquence drops the first séance's identity instead of letting
 * it stand for the whole séquence - which is precisely how the evaluation colouring came to be
 * wrong on that screen.
 */
class ProgressionCalendarBuilderTest extends TestCase
{
    private ProgressionRepository&Stub $progressionRepository;
    private EvaluationRepository&Stub $evaluationRepository;
    private ProgressionCalendarBuilder $builder;
    private Program $program;
    private Topic $topic;
    private User $teacher;
    private SchoolYear $schoolYear;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->progressionRepository = $this->createStub(ProgressionRepository::class);
        $this->evaluationRepository = $this->createStub(EvaluationRepository::class);
        $this->evaluationRepository->method('findTypedForTopicsBetween')->willReturn([]);
        $this->builder = new ProgressionCalendarBuilder($this->progressionRepository, $this->evaluationRepository);

        $this->schoolYear = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30'));
        $cohort = new Cohort('SIO-2', new Track('SIO', new Section('BTS')));

        $this->teacher = new User('teacher');
        $this->program = new Program('SIO-2 2026-2027', 'SIO-2', $cohort, $this->schoolYear);
        $this->program->setTimetableManagementEnabled(true);
        $this->setId($this->program, 7);
        $this->topic = new Topic('Cybersécurité', $this->program, new TopicGroup('Bloc 1', $this->program));
        $this->setId($this->topic, 11);
    }

    // 4a's card names the séquence and carries what it takes to reach the fiche séquence - which is
    // keyed on the SequenceInstance, not on the ProgressionSequence that plans it.
    public function testAnnualCardCarriesTheSequenceSheetIds(): void
    {
        [$progression, $instance] = $this->progressionWithSeances(['Séance 1', 'Séance 2']);
        $this->progressionRepository->method('findForTeacherWithPlacements')->willReturn([$progression]);

        $card = $this->firstAnnualCard();

        self::assertSame('Séquence de test', $card['title'], '4a names the card after the séquence');
        self::assertSame(7, $card['programId']);
        self::assertSame($instance->getId(), $card['sequenceInstanceId']);
        self::assertTrue($card['sheetReachable']);
    }

    // The collapse must not let the FIRST séance stand for the whole séquence: 4a links to the
    // séquence, never to whichever séance happened to come first.
    public function testAnnualCollapseDropsTheFirstSeanceIdentity(): void
    {
        [$progression] = $this->progressionWithSeances(['Séance 1', 'Séance 2']);
        $this->progressionRepository->method('findForTeacherWithPlacements')->willReturn([$progression]);

        self::assertNull($this->firstAnnualCard()['seanceInstanceId']);
    }

    // 4b keeps one card per séance, each naming its own - that is what the anchor on the fiche
    // séquence points at.
    public function testMonthCardsKeepTheirOwnSeance(): void
    {
        [$progression, $instance, $seanceInstances] = $this->progressionWithSeances(['Séance 1', 'Séance 2']);
        $this->progressionRepository->method('findForTeacherWithPlacements')->willReturn([$progression]);

        $cards = $this->monthCards();

        self::assertCount(2, $cards);
        self::assertSame('Séance 1', $cards[0]['title']);
        self::assertSame($seanceInstances[0]->getId(), $cards[0]['seanceInstanceId']);
        self::assertSame($seanceInstances[1]->getId(), $cards[1]['seanceInstanceId']);
        self::assertSame($instance->getId(), $cards[0]['sequenceInstanceId']);
    }

    // The fiche séance proper - app_library_seances_show, phasing included - is keyed on the library
    // TEMPLATE the séance was instantiated from, and the route scopes the séance by its séquence, so
    // a card needs both ids to be able to point at it.
    public function testMonthCardsCarryTheSeanceSheetIds(): void
    {
        [$progression, , , $sequenceTemplate, $seanceTemplates] = $this->progressionWithSeances(['Séance 1', 'Séance 2']);
        $this->progressionRepository->method('findForTeacherWithPlacements')->willReturn([$progression]);

        $cards = $this->monthCards();

        self::assertSame($sequenceTemplate->getId(), $cards[0]['sequenceTemplateId']);
        self::assertSame($seanceTemplates[0]->getId(), $cards[0]['seanceTemplateId']);
        self::assertSame($seanceTemplates[1]->getId(), $cards[1]['seanceTemplateId']);
    }

    // A colleague's library séquence is a 404 for this teacher (SequenceLibraryController narrows
    // every template to its owner, staff aside), so the card must not offer the fiche séance at all
    // - it falls back to the anchor on the fiche séquence, which stays reachable.
    public function testAColleaguesTemplateIsNotOfferedAsASeanceSheet(): void
    {
        [$progression, $instance, $seanceInstances] = $this->progressionWithSeances(['Séance 1'], templateOwner: new User('colleague'));
        $this->progressionRepository->method('findForTeacherWithPlacements')->willReturn([$progression]);

        $card = $this->monthCards()[0];

        self::assertNull($card['sequenceTemplateId']);
        self::assertNull($card['seanceTemplateId']);
        self::assertSame($seanceInstances[0]->getId(), $card['seanceInstanceId'], 'the anchor fallback survives');
        self::assertSame($instance->getId(), $card['sequenceInstanceId']);
    }

    // A séance added straight on screen 2a has no library counterpart at all: no fiche séance, no
    // row to anchor on - the card still links to its séquence rather than to nothing.
    public function testAnAdHocSeanceHasNoAnchorButKeepsItsSequence(): void
    {
        [$progression, $instance] = $this->progressionWithSeances(['Séance 1'], adHoc: true);
        $this->progressionRepository->method('findForTeacherWithPlacements')->willReturn([$progression]);

        $card = $this->firstAnnualCard();

        self::assertNull($card['seanceInstanceId']);
        self::assertNull($card['seanceTemplateId']);
        self::assertSame($instance->getId(), $card['sequenceInstanceId']);
    }

    // 4a stops at the séquence whatever the séances offer: its card stands for the whole of it.
    public function testTheAnnualCardNeverCarriesASeanceSheet(): void
    {
        [$progression] = $this->progressionWithSeances(['Séance 1', 'Séance 2']);
        $this->progressionRepository->method('findForTeacherWithPlacements')->willReturn([$progression]);

        self::assertNull($this->firstAnnualCard()['seanceTemplateId']);
    }

    // The fiche séquence sits behind ProgramFeatureGuardTrait, so on a Program with the timetable
    // feature off it answers 404 - the card has to know not to be a link at all.
    public function testTheSheetIsFlaggedUnreachableWhenTheTimetableFeatureIsOff(): void
    {
        $this->program->setTimetableManagementEnabled(false);
        [$progression] = $this->progressionWithSeances(['Séance 1']);
        $this->progressionRepository->method('findForTeacherWithPlacements')->willReturn([$progression]);

        self::assertFalse($this->firstAnnualCard()['sheetReachable']);
    }

    /** @return list<array<string, mixed>> every 4b card of September, in order */
    private function monthCards(): array
    {
        $cards = [];

        foreach ($this->builder->month($this->teacher, $this->schoolYear, new \DateTimeImmutable('2026-09-01'), [], []) as $week) {
            foreach ($week['days'] as $day) {
                foreach ($day['cards'] as $card) {
                    $cards[] = $card;
                }
            }
        }

        return $cards;
    }

    /** @return array<string, mixed> */
    private function firstAnnualCard(): array
    {
        $months = $this->builder->annual($this->teacher, $this->schoolYear, [], [], new \DateTimeImmutable('2026-09-15'));

        foreach ($months as $month) {
            foreach ($month['classes'] as $class) {
                foreach ($class['cards'] as $card) {
                    return $card;
                }
            }
        }

        self::fail('no card was built');
    }

    /**
     * @param list<string> $titles
     *
     * @return array{0: Progression, 1: SequenceInstance, 2: list<SeanceInstance>, 3: SequenceTemplate|null, 4: list<SeanceTemplate>}
     */
    private function progressionWithSeances(array $titles, bool $adHoc = false, ?User $templateOwner = null): array
    {
        $progression = new Progression($this->topic, $this->teacher);

        $instance = new SequenceInstance($this->program, $this->teacher);
        $instance->setTitre('Séquence de test');
        $this->setId($instance, $this->nextId++);

        // The library origin, which is what the fiche séance is keyed on. Null $templateOwner stands
        // for a séquence with no library counterpart at all.
        $sequenceTemplate = null;
        if (!$adHoc) {
            $sequenceTemplate = new SequenceTemplate($templateOwner ?? $this->teacher);
            $this->setId($sequenceTemplate, $this->nextId++);
            $instance->setSourceTemplate($sequenceTemplate);
        }
        $seanceTemplates = [];

        $sequence = new ProgressionSequence($progression, $instance);
        $this->setId($sequence, $this->nextId++);

        $seanceInstances = [];
        $day = new \DateTimeImmutable('2026-09-01');

        foreach ($titles as $position => $title) {
            $seance = new ProgressionSeance($sequence, $title);
            $seance->setPosition($position);

            // Null exactly when $adHoc, so this one check covers both.
            if (null !== $sequenceTemplate) {
                $seanceTemplate = new SeanceTemplate($sequenceTemplate);
                $seanceTemplate->setTitre($title);
                $this->setId($seanceTemplate, $this->nextId++);
                $seanceTemplates[] = $seanceTemplate;

                $seanceInstance = new SeanceInstance($this->program, $this->teacher);
                $seanceInstance->setTitre($title);
                $seanceInstance->setSourceTemplate($seanceTemplate);
                $this->setId($seanceInstance, $this->nextId++);
                $seance->setSeanceInstance($seanceInstance);
                $seanceInstances[] = $seanceInstance;
            }

            $session = new LessonSession($this->program);
            $session->setDay($day->modify(sprintf('+%d days', $position)));
            $session->setStartHour(new \DateTimeImmutable('2026-09-01 08:00'));
            $session->setEndHour(new \DateTimeImmutable('2026-09-01 10:00'));
            $session->setTopic($this->topic);
            $this->setId($session, $this->nextId++);

            new ProgressionSeancePlacement($seance, $session);
        }

        return [$progression, $instance, $seanceInstances, $sequenceTemplate, $seanceTemplates];
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

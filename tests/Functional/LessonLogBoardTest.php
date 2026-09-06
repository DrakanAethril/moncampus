<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LessonLog;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The cahier de texte's landing screen, driven through real requests.
 *
 * What is pinned here is what an address can ask of it, because that is the part no unit test can
 * see: that arriving with nothing lands on the current week grouped by class, that the class-scoped
 * route is this same screen narrowed and locked to the chronological list, that `?date=` moves the
 * period, and that a date the teacher has no séance on says so rather than showing a silent list.
 */
class LessonLogBoardTest extends FunctionalTestCase
{
    private User $teacher;
    private Program $monday;
    private Program $wednesday;
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'jsautour');

        // Two classes, one séance each, in the week that contains today - the period the screen
        // opens on when nothing asks for another.
        // One author for both, createProgram() creating its own otherwise - and two of them would
        // collide on the username's UNIQUE index.
        $this->author = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'fixture.author');
        $this->monday = $this->createProgram(teachers: [$this->teacher], author: $this->author);
        $this->wednesday = $this->createProgram(teachers: [$this->teacher], author: $this->author);

        $this->addSession($this->monday, $this->weekStart(), 'Cybersécurité');
        $this->addSession($this->wednesday, $this->weekStart()->modify('+2 days'), 'Réseaux');
    }

    private function weekStart(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('today'))->modify('monday this week');
    }

    private function addSession(Program $program, \DateTimeImmutable $day, string $topicName): LessonSession
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        // A Topic hangs off a TopicGroup at the entity level (topic_group_id is NOT NULL), so the
        // matière cannot be created on its own.
        $group = new TopicGroup('Tronc commun', $program);
        $group->setCreatedBy($this->author);
        $entityManager->persist($group);

        $topic = new Topic($topicName, $program, $group);
        $topic->setCreatedBy($this->author);
        $entityManager->persist($topic);

        $session = new LessonSession($program);
        $session->setDay($day);
        $session->setStartHour(new \DateTimeImmutable('1970-01-01 08:00'));
        $session->setEndHour(new \DateTimeImmutable('1970-01-01 10:00'));
        // Decimal HOURS, unlike a séance's minutes - see the entity's own note.
        $session->setLength('2');
        $session->setTopic($topic);
        $session->setTeacher($this->teacher);
        $entityManager->persist($session);
        $entityManager->flush();

        return $session;
    }

    public function testThePreviewKeepsTheFormattingTheEditorGaveTheContent(): void
    {
        // The preview used to flatten the cahier de texte into one stripped, nowrap line. It read
        // as a different text from the one that was written - and, a nowrap child setting the
        // min-content width of its column, it pushed the whole screen past the viewport and carried
        // « Ouvrir la séance » off the right edge with it.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $session = $this->addSession($this->monday, $this->weekStart()->modify('+4 days'), 'Supervision');

        $log = new LessonLog($session);
        $log->setCreatedBy($this->teacher);
        $log->setContenuRealise('<p><strong>Chapitre 3</strong> — le <u>routage inter-VLAN</u></p><ul><li>Adressage</li></ul>');
        $entityManager->persist($log);
        $entityManager->flush();

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/lesson-log?seance='.$session->getId());

        self::assertResponseIsSuccessful();
        $content = $crawler->filter('.cm-cdt-detail:not([hidden]) .cm-cdt-part__content');
        self::assertCount(1, $content);
        self::assertStringContainsString('<strong>Chapitre 3</strong>', $content->html());
        self::assertStringContainsString('<u>routage inter-VLAN</u>', $content->html());
        self::assertStringContainsString('<li>Adressage</li>', $content->html());
    }

    public function testItOpensOnTheCurrentWeekGroupedByClassWithoutAskingForOne(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/lesson-log');

        self::assertResponseIsSuccessful();
        // No class picker on the way in: both classes are on screen at once.
        self::assertCount(2, $crawler->filter('.cm-cdt-class'));
        self::assertSame('Par classe', trim($crawler->filter('.cm-cdt-modes__tab.is-active')->text()));
        // The first class of the period is the one unfolded, and its séance the one previewed.
        self::assertCount(1, $crawler->filter('.cm-cdt-class.is-open'));
        self::assertCount(1, $crawler->filter('.cm-cdt-seance.is-selected'));
    }

    public function testTheClassScopedRouteIsTheSameScreenNarrowedToThatClass(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/programs/'.$this->wednesday->getId().'/lesson-log');

        self::assertResponseIsSuccessful();
        // Its own séance, and not the other class's.
        self::assertStringContainsString('Réseaux', $crawler->filter('.cm-cdt-days')->text());
        self::assertStringNotContainsString('Cybersécurité', $crawler->filter('.cm-cdt-days')->text());
        // Grouping by class inside one class draws a single group holding everything, so the tab
        // stays on screen and leads nowhere.
        self::assertSame('Chronologique', trim($crawler->filter('.cm-cdt-modes__tab.is-active')->text()));
        self::assertCount(1, $crawler->filter('.cm-cdt-modes__tab.is-disabled'));
        self::assertCount(0, $crawler->filter('.cm-cdt-classes'));
    }

    public function testTheClassPickerNamesTheClassOnScreen(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/programs/'.$this->wednesday->getId().'/lesson-log');

        $selected = $crawler->filter('.cm-cdt-classpick__select option[selected]');
        self::assertSame(
            '/programs/'.$this->wednesday->getId().'/lesson-log',
            $selected->attr('value'),
        );
        // Both classes are offered whatever the week, plus the way back to all of them.
        self::assertCount(3, $crawler->filter('.cm-cdt-classpick__select option'));
    }

    public function testTheAllClassesScreenPreselectsThatOption(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/lesson-log');

        self::assertSame('/lesson-log', $crawler->filter('.cm-cdt-classpick__select option[selected]')->attr('value'));
    }

    public function testTheOldClassParameterIsSentToTheRoute(): void
    {
        // One address per screen: `?class=` was a second way of naming the class and is now only a
        // way in. The week it named travels with it, so an old link does not land on today.
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/lesson-log?class='.$this->wednesday->getId().'&week=2026-09-07');

        self::assertResponseRedirects('/programs/'.$this->wednesday->getId().'/lesson-log?week=2026-09-07');
    }

    public function testAClassTheViewerDoesNotTeachIsRefused(): void
    {
        $outsider = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'outsider');

        $this->client->loginUser($outsider);
        $this->client->request('GET', '/programs/'.$this->wednesday->getId().'/lesson-log');

        self::assertResponseStatusCodeSame(403);
    }

    public function testADateInTheLinkOpensTheChronologicalListOnThatDay(): void
    {
        $day = $this->weekStart()->modify('+2 days');

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/lesson-log?date='.$day->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSame('Chronologique', trim($crawler->filter('.cm-cdt-modes__tab.is-active')->text()));
        // That day, and only that day, is unfolded - and its séance is the one previewed.
        self::assertCount(1, $crawler->filter('.cm-cdt-day.is-open'));
        self::assertStringContainsString('Réseaux', $crawler->filter('.cm-cdt-day.is-open')->text());
        self::assertCount(1, $crawler->filter('.cm-cdt-seance.is-selected'));
    }

    public function testADateWithoutSeanceIsSaidRatherThanLeftSilent(): void
    {
        // Tuesday: the teacher has séances on Monday and Wednesday only.
        $day = $this->weekStart()->modify('+1 day');

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/lesson-log?date='.$day->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.cm-cdt-notice'));
        // Nothing is previewed either: the screen must not answer with an unrelated séance.
        self::assertCount(0, $crawler->filter('.cm-cdt-seance.is-selected'));
        self::assertCount(1, $crawler->filter('[data-lesson-log-board-target="placeholder"]:not([hidden])'));
    }

    public function testTheWeekOnDisplayFollowsTheDateEvenFarFromToday(): void
    {
        $day = $this->weekStart()->modify('+35 days');

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/lesson-log?date='.$day->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        // Nothing that week, so both lists are empty - but the period really did move, which is
        // what the picker's own week is stamped with.
        self::assertSame(
            $day->modify('monday this week')->format('Y-m-d'),
            $crawler->filter('[data-lesson-log-period-week-value]')->attr('data-lesson-log-period-week-value'),
        );
        self::assertCount(1, $crawler->filter('.cm-cdt-days .cm-cdt-empty'));
    }

    public function testASeanceInTheLinkBringsItsOwnWeekAlongWithIt(): void
    {
        // A séance five weeks away, named by a link that says nothing about the period - a
        // bookmark, the way back up from the séance page, the address a previous visit left behind.
        // The period has to follow it: the séance was otherwise silently swapped for the current
        // week's first one, and nothing on the screen led to the one that was named.
        $day = $this->weekStart()->modify('+35 days');
        $session = $this->addSession($this->monday, $day, 'Supervision');

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/lesson-log?seance='.$session->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            $day->modify('monday this week')->format('Y-m-d'),
            $crawler->filter('[data-lesson-log-period-week-value]')->attr('data-lesson-log-period-week-value'),
        );
        // And it is that séance which is previewed, with the way into it on screen.
        self::assertSame((string) $session->getId(), $crawler->filter('.cm-cdt-seance.is-selected')->attr('data-seance-id'));
        self::assertStringContainsString(
            '/programs/'.$this->monday->getId().'/timetable/sessions/'.$session->getId().'/log',
            (string) $crawler->filter('.cm-cdt-detail:not([hidden]) .cm-cdt-detail__open')->attr('href'),
        );
    }

    public function testAnExplicitWeekStillWinsOverTheSeanceInTheLink(): void
    {
        // What the ‹ › arrows produce: they carry the séance of the week they are leaving, and the
        // period must not be pulled straight back to it.
        $session = $this->addSession($this->monday, $this->weekStart()->modify('+35 days'), 'Supervision');
        $nextWeek = $this->weekStart()->modify('+7 days');

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/lesson-log?week='.$nextWeek->format('Y-m-d').'&seance='.$session->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            $nextWeek->format('Y-m-d'),
            $crawler->filter('[data-lesson-log-period-week-value]')->attr('data-lesson-log-period-week-value'),
        );
    }

    public function testASeanceTheViewerDoesNotDeliverLeavesThePeriodWhereItIs(): void
    {
        // A colleague's séance names a week this screen could not show anyway - the list holds the
        // viewer's own créneaux. Moving the period would only land on an empty week.
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'other.teacher');
        $session = $this->addSession($this->monday, $this->weekStart()->modify('+35 days'), 'Supervision');
        $session->setTeacher($colleague);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/lesson-log?seance='.$session->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            $this->weekStart()->format('Y-m-d'),
            $crawler->filter('[data-lesson-log-period-week-value]')->attr('data-lesson-log-period-week-value'),
        );
    }

    public function testASeanceOfSomebodyElseIsNotOnTheScreen(): void
    {
        $colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'colleague');
        $this->monday->addTeacher($colleague);

        $this->client->loginUser($colleague);
        $crawler = $this->client->request('GET', '/lesson-log');

        self::assertResponseIsSuccessful();
        // It is the viewer's own cahier de texte, not the class's: teaching nothing shows nothing.
        self::assertCount(0, $crawler->filter('.cm-cdt-class'));
        self::assertCount(1, $crawler->filter('.cm-cdt-classes .cm-cdt-empty'));
    }
}

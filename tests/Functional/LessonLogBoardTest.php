<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The cahier de texte's landing screen, driven through real requests.
 *
 * What is pinned here is what a link can ask of it, because that is the part no unit test can see:
 * that arriving with nothing lands on the current week grouped by class, that `?class=` unfolds a
 * class, that `?date=` switches to the chronological list on the right week, and that a date the
 * teacher has no séance on says so rather than showing a silent list.
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

    public function testAClassInTheLinkIsTheOneUnfolded(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/lesson-log?class='.$this->wednesday->getId());

        self::assertResponseIsSuccessful();
        self::assertSame('Par classe', trim($crawler->filter('.cm-cdt-modes__tab.is-active')->text()));
        self::assertStringContainsString(
            'Réseaux',
            $crawler->filter('.cm-cdt-class.is-open')->text(),
        );
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

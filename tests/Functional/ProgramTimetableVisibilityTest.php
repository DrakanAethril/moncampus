<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\User;
use App\Enum\VisibilityLevel;
use App\Service\Calendar\CalendarTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * « Visibilité de l'emploi du temps » is cumulative with the feature, never overridden by it.
 *
 * The regression this pins is one way round and one way only: the `timetable` feature was opened
 * to students, and from that day a formation set to « Admin only » served its timetable to its
 * own students anyway - on the dashboard, on the tab, and on the screen behind them. The tier was
 * read by the class submenu alone, which is a menu students never see.
 *
 * Every feature is lit here (FunctionalTestCase::setUp), which is the point: what closes these
 * screens must be the formation's own setting and nothing else.
 */
class ProgramTimetableVisibilityTest extends FunctionalTestCase
{
    public function testAStudentReadsTheTimetableOfAFormationOpenToEveryone(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'timetable.student');
        $program = $this->programFor($student, VisibilityLevel::Everyone);

        $this->assertScreens($student, [
            '/my/timetable' => 302,
            sprintf('/programs/%d/timetable', $program->getId()) => 200,
            sprintf('/programs/%d/timetable/feed', $program->getId()) => 200,
        ]);

        self::assertContains('/my/timetable', $this->navHrefs($student));
    }

    public function testAFormationReservedToTheAdministrationHidesItFromItsOwnStudents(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'timetable.student');
        $program = $this->programFor($student, VisibilityLevel::AdminOnly);

        // 404 rather than 403 throughout, like an extinguished feature: a timetable the formation
        // did not open does not exist for this reader, it is not forbidden to them. The feed
        // answers the same way as the screen - guarding the page alone would leave the sessions
        // one URL away.
        $this->assertScreens($student, [
            '/my/timetable' => 404,
            sprintf('/programs/%d/timetable', $program->getId()) => 404,
            sprintf('/programs/%d/timetable/feed', $program->getId()) => 404,
        ]);

        self::assertNotContains('/my/timetable', $this->navHrefs($student));
    }

    /**
     * The dashboard is where it was seen first, and it reads its own query rather than the nav's -
     * which is exactly how the two came to disagree.
     */
    public function testTheStudentDashboardDropsTheDayCardOfAClosedFormation(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'timetable.student');
        $this->programFor($student, VisibilityLevel::AdminOnly);

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('a[href="/my/timetable"]'));
    }

    /**
     * « Enseignants uniquement » is the tier that separates the two readers, so it is the one that
     * proves the rule is a hierarchy rather than an on/off switch: the same formation, the same
     * lit feature, closed to the student and open to the teacher.
     */
    public function testTheTierSeparatesTheStudentFromTheTeacher(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'timetable.student');
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'timetable.teacher');
        $program = $this->createProgram([$student], [$teacher]);
        $program->setTimetableVisibility(VisibilityLevel::TeachersOnly);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $path = sprintf('/programs/%d/timetable', $program->getId());
        $this->assertScreens($student, [$path => 404]);
        $this->assertScreens($teacher, [$path => 200]);
    }

    /**
     * The iCalendar subscription is a third URL carrying the same séances, and the only one reached
     * **without a session** - an agenda fetches it from Google's or Apple's servers. So the tier has
     * to close it exactly as it closes the screen and the feed, and it is closed here by the token's
     * owner, not by whoever is holding the link.
     */
    public function testTheSubscriptionAnswersTheSameWayAsTheScreenItCopies(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'timetable.student');
        $program = $this->programFor($student, VisibilityLevel::Everyone);

        $token = static::getContainer()->get(CalendarTokenManager::class)->tokenFor($student);

        // Nobody is logged in for either request - that is the point of the token.
        self::assertSame(200, $this->fetchIcs($token, $program));

        // The same formation, the same link, closed after the subscription was made: a tier moved
        // in Paramétrage has to reach the agendas that are already subscribed, not just the screen.
        $program->setTimetableVisibility(VisibilityLevel::AdminOnly);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame(404, $this->fetchIcs($token, $program));
    }

    public function testATokenNobodyHoldsOpensNothing(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'timetable.student');
        $program = $this->programFor($student, VisibilityLevel::Everyone);

        static::getContainer()->get(CalendarTokenManager::class)->tokenFor($student);

        self::assertSame(404, $this->fetchIcs(str_repeat('f', 64), $program));
    }

    /**
     * Rotating is what takes a leaked link back: the old URL has to stop resolving the moment a new
     * token is minted, or « Régénérer le lien » is a button that promises something it does not do.
     */
    public function testRotatingTheTokenClosesEverySubscriptionMadeFromTheOldOne(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'timetable.student');
        $program = $this->programFor($student, VisibilityLevel::Everyone);

        $tokens = static::getContainer()->get(CalendarTokenManager::class);
        $old = $tokens->tokenFor($student);
        self::assertSame(200, $this->fetchIcs($old, $program));

        $new = $tokens->rotate($student);

        self::assertNotSame($old, $new);
        self::assertSame(404, $this->fetchIcs($old, $program));
        self::assertSame(200, $this->fetchIcs($new, $program));
    }

    /**
     * The `hide` parameter can only ever remove. It is unsigned, so anybody holding the link can
     * rewrite it - and nothing it can be set to widens the feed beyond what the token's owner may
     * already read.
     */
    public function testTheHideParameterOnlyEverRemoves(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'timetable.student');
        $closed = $this->programFor($student, VisibilityLevel::AdminOnly);

        $token = static::getContainer()->get(CalendarTokenManager::class)->tokenFor($student);

        self::assertSame(404, $this->fetchIcs($token, $closed, '?hide='));
        self::assertSame(404, $this->fetchIcs($token, $closed, '?hide=default,1,2'));
    }

    private function fetchIcs(string $token, Program $program, string $query = ''): int
    {
        $this->client->request('GET', \sprintf('/calendar/%s/program/%d.ics%s', $token, $program->getId(), $query));

        return $this->client->getResponse()->getStatusCode();
    }

    private function programFor(User $student, VisibilityLevel $tier): Program
    {
        $program = $this->createProgram([$student]);
        $program->setTimetableVisibility($tier);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $program;
    }

    /** @return list<string> */
    private function navHrefs(User $user): array
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        return $crawler->filter('header.navbar a')->each(
            static fn (Crawler $link): string => (string) $link->attr('href'),
        );
    }
}

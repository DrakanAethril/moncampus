<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\LessonSession;
use App\Entity\Progression;
use App\Entity\Topic;
use App\Entity\User;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgressionRepository;
use App\Security\LessonLogEditors;
use PHPUnit\Framework\TestCase;

/**
 * Who may write in one séance's cahier de texte: the teacher who delivers it, and their
 * co-animator.
 *
 * « Co-animator » is two different facts and both count, because the platform records co-animation
 * in two places and neither subsumes the other: the emploi du temps knows the twin créneau (the
 * other half of the class, same matière, same hour, someone else at the board), and the progression
 * knows the second formateur named on the plan. A pair may exist in one and not in the other - two
 * teachers whose groups are timetabled on different days are co-animating without ever standing in
 * twin créneaux.
 *
 * The progression door only opens on a plan that is actually co-animated, and then to everyone
 * named on it, owner included. Otherwise it would be one-way: the co-teacher could write in the
 * owner's séance and the owner could not write in theirs.
 */
class LessonLogEditorsTest extends TestCase
{
    private function session(?User $teacher, ?Topic $topic = null): LessonSession
    {
        $session = $this->createStub(LessonSession::class);
        $session->method('getTeacher')->willReturn($teacher);
        $session->method('getTopic')->willReturn($topic);

        return $session;
    }

    /**
     * @param list<LessonSession> $twins
     */
    private function editors(array $twins = [], ?Progression $progression = null): LessonLogEditors
    {
        $sessions = $this->createStub(LessonSessionRepository::class);
        $sessions->method('findTwinsOf')->willReturn($twins);

        $progressions = $this->createStub(ProgressionRepository::class);
        $progressions->method('findOneForTopic')->willReturn($progression);

        return new LessonLogEditors($sessions, $progressions);
    }

    private function user(string $username): User
    {
        $user = new User($username);
        $user->setRoles(['ROLE_USER', 'ROLE_TEACHER']);

        return $user;
    }

    public function testTheTeacherOfTheSeanceWrites(): void
    {
        $teacher = $this->user('owner');

        self::assertTrue($this->editors()->mayEdit($this->session($teacher), $teacher));
    }

    public function testAColleagueOfTheClassDoesNot(): void
    {
        self::assertFalse($this->editors()->mayEdit($this->session($this->user('owner')), $this->user('other')));
    }

    public function testTheTeacherOfATwinCreneauWrites(): void
    {
        $other = $this->user('other');
        $twin = $this->session($other);

        self::assertTrue($this->editors([$twin])->mayEdit($this->session($this->user('owner')), $other));
    }

    public function testATwinHeldBySomebodyElseAgainOpensNothing(): void
    {
        $twin = $this->session($this->user('third'));

        self::assertFalse($this->editors([$twin])->mayEdit($this->session($this->user('owner')), $this->user('other')));
    }

    public function testEverybodyNamedOnACoAnimatedProgressionWrites(): void
    {
        $owner = $this->user('owner');
        $second = $this->user('second');

        $topic = $this->createStub(Topic::class);
        $progression = new Progression($topic, $owner);
        $progression->addCoTeacher($second);

        // Both ways round: the plan's second formateur writes in the owner's séance, and the owner
        // writes in the second formateur's.
        self::assertTrue($this->editors([], $progression)->mayEdit($this->session($owner, $topic), $second));
        self::assertTrue($this->editors([], $progression)->mayEdit($this->session($second, $topic), $owner));
    }

    public function testAProgressionNobodyCoAnimatesOpensNothing(): void
    {
        $owner = $this->user('owner');
        $topic = $this->createStub(Topic::class);
        $progression = new Progression($topic, $owner);

        // The owner of a solo plan is not thereby an editor of a colleague's créneau on the same
        // matière - which is what a reassigned Topic would otherwise produce.
        self::assertFalse($this->editors([], $progression)->mayEdit($this->session($this->user('other'), $topic), $owner));
    }

    public function testASeanceWithNoTopicNeedsNoProgressionLookup(): void
    {
        self::assertFalse($this->editors()->mayEdit($this->session($this->user('owner')), $this->user('other')));
    }

    public function testASeanceNobodyDeliversIsWrittenByNobody(): void
    {
        // A créneau whose teacher the emploi du temps never filled in: there is no « enseignant qui
        // assure la séance », so the rule has nobody to let in. It is a gap in the timetable, and
        // answering « anyone » would be the wrong way to close it.
        self::assertFalse($this->editors()->mayEdit($this->session(null), $this->user('other')));
    }
}

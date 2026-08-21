<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\GuestAccount;
use App\Entity\Program;
use App\Entity\User;
use App\Entity\VmBatch;
use App\Security\Voter\GuestAccountVoter;
use App\Security\Voter\GuestConsoleVoter;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * The rule that decides who gets a root-capable terminal on a machine full of students.
 *
 * The refusal that matters is a student's, and it is proved here rather than promised by a
 * template: a card that does not draw the button hides the console, and hiding is not refusing.
 * One case per role, and the two halves of the rule are tested apart - because each of them on its
 * own would look like it works.
 */
class GuestConsoleVoterTest extends VoterTestCase
{
    public function testATeacherOfTheClassWhoHoldsTheAccountPasses(): void
    {
        $teacher = $this->identified(1, ['ROLE_USER', 'ROLE_TEACHER'], 'a.dubois');

        $this->assertGranted(
            new GuestConsoleVoter(),
            $teacher,
            $this->account($teacher, [$teacher]),
            GuestConsoleVoter::CONSOLE,
        );
    }

    /**
     * The half nobody thinks to test: a student *does* hold an account on the machine - that is the
     * whole point of the machine - so the ownership half passes for them. Only the teaching half
     * refuses, and it must, whatever roles they carry.
     */
    public function testAStudentOfTheClassIsRefusedEvenThoughTheAccountIsTheirs(): void
    {
        $teacher = $this->identified(1, ['ROLE_USER', 'ROLE_TEACHER'], 'a.dubois');
        $student = $this->identified(2, ['ROLE_USER', 'ROLE_STUDENT'], 'm.dupont');

        $account = $this->account($student, [$teacher]);

        // The other voter still grants: the machine is theirs to start, stop and set a password on.
        $this->assertGranted(new GuestAccountVoter(), $student, $account, GuestAccountVoter::OWN);
        $this->assertDenied(new GuestConsoleVoter(), $student, $account, GuestConsoleVoter::CONSOLE);
    }

    /** A teacher of another class is a stranger to this machine, account or no account. */
    public function testATeacherWhoDoesNotTeachThisClassIsRefused(): void
    {
        $stranger = $this->identified(3, ['ROLE_USER', 'ROLE_TEACHER'], 'k.bouchard');
        $holder = $this->identified(1, ['ROLE_USER', 'ROLE_TEACHER'], 'a.dubois');

        $this->assertDenied(
            new GuestConsoleVoter(),
            $stranger,
            $this->account($stranger, [$holder]),
            GuestConsoleVoter::CONSOLE,
        );
    }

    /** Teaching the class is not enough either: the account has to be the person's own. */
    public function testATeacherOfTheClassWithoutAnAccountOnThisMachineIsRefused(): void
    {
        $teacher = $this->identified(1, ['ROLE_USER', 'ROLE_TEACHER'], 'a.dubois');
        $colleague = $this->identified(4, ['ROLE_USER', 'ROLE_TEACHER'], 'j.morel');

        $this->assertDenied(
            new GuestConsoleVoter(),
            $colleague,
            $this->account($teacher, [$teacher, $colleague]),
            GuestConsoleVoter::CONSOLE,
        );
    }

    /**
     * No role stands in for the rule - and ROLE_STAFF above all. An administrator has their own
     * door, /infrastructure, guarded by access_control; a bypass here would be a third one.
     */
    public function testNoRoleOpensThisDoor(): void
    {
        $teacher = $this->identified(1, ['ROLE_USER', 'ROLE_TEACHER'], 'a.dubois');

        foreach ([['ROLE_USER', 'ROLE_ADMIN'], ['ROLE_USER', 'ROLE_STAFF'], ['ROLE_USER', 'ROLE_STAFF-LEAD'], ['ROLE_USER', 'ROLE_TUTOR']] as $roles) {
            $this->assertDenied(
                new GuestConsoleVoter(),
                $this->identified(9, $roles, 'somebody'),
                $this->account($teacher, [$teacher]),
                GuestConsoleVoter::CONSOLE,
            );
        }
    }

    /** A machine outside any batch names no class, so there is nobody who teaches it. */
    public function testAMachineThatBelongsToNoClassHasNoTeacherDoor(): void
    {
        $teacher = $this->identified(1, ['ROLE_USER', 'ROLE_TEACHER'], 'a.dubois');
        $account = $this->createStub(GuestAccount::class);
        $account->method('getUser')->willReturn($teacher);
        $account->method('getBatch')->willReturn(null);

        $this->assertDenied(new GuestConsoleVoter(), $teacher, $account, GuestConsoleVoter::CONSOLE);
    }

    /** Anonymous is not a person, and an unrelated attribute is not this voter's business. */
    public function testItStaysOutOfDecisionsThatAreNotItsOwn(): void
    {
        $teacher = $this->identified(1, ['ROLE_USER', 'ROLE_TEACHER'], 'a.dubois');

        $this->assertDenied(new GuestConsoleVoter(), null, $this->account($teacher, [$teacher]), GuestConsoleVoter::CONSOLE);
        $this->assertAbstains(new GuestConsoleVoter(), $teacher, $this->account($teacher, [$teacher]), GuestAccountVoter::OWN);
    }

    /** @param list<User> $teachers */
    private function account(?User $owner, array $teachers): GuestAccount
    {
        $program = $this->createStub(Program::class);
        $program->method('getTeachers')->willReturn(new ArrayCollection($teachers));

        $batch = $this->createStub(VmBatch::class);
        $batch->method('getProgram')->willReturn($program);

        $account = $this->createStub(GuestAccount::class);
        $account->method('getUser')->willReturn($owner);
        $account->method('getBatch')->willReturn($batch);

        return $account;
    }

    /** @param list<string> $roles */
    private function identified(int $id, array $roles, string $username): User
    {
        $user = $this->user($roles, $username);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}

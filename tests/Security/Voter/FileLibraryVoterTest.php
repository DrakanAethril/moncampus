<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Security\Voter\FileLibraryVoter;

/**
 * The access rule of the file library, which is one line and two halves: **who has a library** and
 * **whose library it is**.
 *
 * The case that matters most here is the one the wiki answers the other way: a teacher, a staff
 * lead, even an admin gets **nothing** on somebody else's library. There is no supervision rule,
 * deliberately - a library holds its owner's own material and contains no student production - and a
 * test is what keeps that true the day somebody adds a "just for support" branch.
 */
class FileLibraryVoterTest extends VoterTestCase
{
    public function testAnOwnerMayDoEverythingInsideTheirOwnLibrary(): void
    {
        $voter = new FileLibraryVoter();
        $teacher = $this->identifiedUser(1, ['ROLE_USER', 'ROLE_TEACHER']);

        foreach ([FileLibraryVoter::VIEW, FileLibraryVoter::EDIT, FileLibraryVoter::LINK] as $attribute) {
            $this->assertGranted($voter, $teacher, $this->nodeOwnedBy($teacher), $attribute);
            // A null subject is the third question - "do I have a library at all" - and the menu and
            // the screens ask it that way.
            $this->assertGranted($voter, $teacher, null, $attribute);
        }
    }

    public function testNobodyReadsSomebodyElsesLibrary(): void
    {
        $voter = new FileLibraryVoter();
        $owner = $this->identifiedUser(1, ['ROLE_USER', 'ROLE_TEACHER']);
        $node = $this->nodeOwnedBy($owner);

        foreach ([
            'another teacher' => $this->identifiedUser(2, ['ROLE_USER', 'ROLE_TEACHER']),
            'a staff member' => $this->identifiedUser(3, ['ROLE_USER', 'ROLE_STAFF']),
            'a staff lead' => $this->identifiedUser(4, ['ROLE_USER', 'ROLE_STAFF-LEAD']),
            // The narrow admin row of the access table is the quota card, and nothing else - it does
            // not go through this Voter, which is what keeps "see the total, set the number" from
            // quietly becoming "open the folders".
            'an admin' => $this->identifiedUser(5, ['ROLE_USER', 'ROLE_ADMIN']),
        ] as $who => $user) {
            $this->assertDenied($voter, $user, $node, FileLibraryVoter::VIEW, $who.' must not read another library');
            $this->assertDenied($voter, $user, $node, FileLibraryVoter::EDIT, $who.' must not change another library');
        }
    }

    public function testAStudentATutorAndAnExternalHaveNoLibraryAtAll(): void
    {
        $voter = new FileLibraryVoter();

        foreach ([
            'a student' => $this->identifiedUser(6, ['ROLE_USER', 'ROLE_STUDENT']),
            'a tutor' => $this->identifiedUser(7, ['ROLE_USER', 'ROLE_TUTOR']),
            'an external' => $this->identifiedUser(8, ['ROLE_USER', 'ROLE_EXTERNAL']),
        ] as $who => $user) {
            $this->assertDenied($voter, $user, null, FileLibraryVoter::VIEW, $who.' must not reach the library');
            $this->assertDenied($voter, $user, $this->nodeOwnedBy($user), FileLibraryVoter::EDIT, $who.' must not own one either');
        }
    }

    public function testTeachersAndPersonnelAreExactlyWhoHasOne(): void
    {
        $voter = new FileLibraryVoter();

        // Written once, and asked of the *row* rather than of the session - the admin's quota card
        // asks this question about somebody else.
        self::assertTrue($voter->hasLibrary($this->user(['ROLE_USER', 'ROLE_TEACHER'])));
        self::assertTrue($voter->hasLibrary($this->user(['ROLE_USER', 'ROLE_STAFF'])));
        self::assertTrue($voter->hasLibrary($this->user(['ROLE_USER', 'ROLE_STAFF-LEAD'])));
        self::assertTrue($voter->hasLibrary($this->user(['ROLE_USER', 'ROLE_ADMIN'])));

        self::assertFalse($voter->hasLibrary($this->user(['ROLE_USER', 'ROLE_STUDENT'])));
        self::assertFalse($voter->hasLibrary($this->user(['ROLE_USER', 'ROLE_TUTOR'])));
        self::assertFalse($voter->hasLibrary($this->user(['ROLE_USER', 'ROLE_EXTERNAL'])));
    }

    public function testAnAnonymousRequestIsRefusedAndOtherAttributesAreLeftAlone(): void
    {
        $voter = new FileLibraryVoter();
        $teacher = $this->identifiedUser(1, ['ROLE_USER', 'ROLE_TEACHER']);

        $this->assertDenied($voter, null, null, FileLibraryVoter::VIEW);
        // A Voter must stay out of decisions that are not its own.
        $this->assertAbstains($voter, $teacher, $this->nodeOwnedBy($teacher), 'WIKI_EDIT');
    }

    /** A User with an id: the rule compares ids, so the stub needs one. */
    private function identifiedUser(int $id, array $roles): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);

        return $user;
    }

    private function nodeOwnedBy(User $owner): FileLibraryNode
    {
        $node = $this->createStub(FileLibraryNode::class);
        $node->method('getOwner')->willReturn($owner);

        return $node;
    }
}

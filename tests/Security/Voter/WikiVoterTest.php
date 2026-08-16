<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Program;
use App\Entity\User;
use App\Entity\Wiki;
use App\Enum\WikiType;
use App\Security\Voter\WikiVoter;
use App\Service\WikiAccess;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * The Voter's own job, which is *not* the access rule - that lives in App\Service\WikiAccess and is
 * pinned in its own test. What is asserted here is the mapping: the four attributes reach the three
 * methods, WIKI_VIEW answers exactly as WIKI_EDIT does, and enrolment in an assigned class - the
 * one fact WikiAccess cannot resolve on its own - actually reaches it.
 */
class WikiVoterTest extends VoterTestCase
{
    public function testViewIsADocumentedAliasOfEdit(): void
    {
        $voter = new WikiVoter(new WikiAccess());
        $owner = $this->identifiedUser(1, ['ROLE_USER', 'ROLE_STUDENT']);
        $wiki = $this->personalWiki($owner);

        $this->assertGranted($voter, $owner, $wiki, WikiVoter::VIEW);
        $this->assertGranted($voter, $owner, $wiki, WikiVoter::EDIT);
    }

    public function testTheThreeVerbsAnswerSeparatelyOnAStudentsPersonalWiki(): void
    {
        $voter = new WikiVoter(new WikiAccess());
        $owner = $this->identifiedUser(1, ['ROLE_USER', 'ROLE_STUDENT']);
        $teacher = $this->identifiedUser(2, ['ROLE_USER', 'ROLE_TEACHER']);
        $wiki = $this->personalWiki($owner);

        $this->assertGranted($voter, $teacher, $wiki, WikiVoter::EDIT);
        $this->assertGranted($voter, $teacher, $wiki, WikiVoter::MANAGE);
        // Removing a year of somebody's work is an admin's call, not a teacher's.
        $this->assertDenied($voter, $teacher, $wiki, WikiVoter::DELETE);
    }

    public function testAStudentOfAnAssignedClassReachesTheWikiWithoutBeingNamedInIt(): void
    {
        $voter = new WikiVoter(new WikiAccess());
        $enrolled = $this->identifiedUser(3, ['ROLE_USER', 'ROLE_STUDENT']);
        $stranger = $this->identifiedUser(4, ['ROLE_USER', 'ROLE_STUDENT']);

        $program = $this->createStub(Program::class);
        $program->method('getStudents')->willReturn(new ArrayCollection([$enrolled]));

        $wiki = $this->createStub(Wiki::class);
        $wiki->method('getType')->willReturn(WikiType::Shared);
        $wiki->method('getOwner')->willReturn(null);
        $wiki->method('getCreatedBy')->willReturn($this->identifiedUser(9, ['ROLE_USER', 'ROLE_TEACHER']));
        $wiki->method('getMemberIds')->willReturn([]);
        $wiki->method('getMemberRoles')->willReturn([]);
        $wiki->method('getPrograms')->willReturn(new ArrayCollection([$program]));

        $this->assertGranted($voter, $enrolled, $wiki, WikiVoter::EDIT);
        $this->assertDenied($voter, $stranger, $wiki, WikiVoter::EDIT);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $voter = new WikiVoter(new WikiAccess());
        $user = $this->identifiedUser(1, ['ROLE_USER', 'ROLE_ADMIN']);

        $this->assertAbstains($voter, $user, $this->personalWiki($user), 'SOMETHING_ELSE');
        $this->assertAbstains($voter, $user, new \stdClass(), WikiVoter::EDIT);
    }

    public function testAnAnonymousTokenIsDenied(): void
    {
        $voter = new WikiVoter(new WikiAccess());
        $owner = $this->identifiedUser(1, ['ROLE_USER', 'ROLE_STUDENT']);

        $this->assertDenied($voter, null, $this->personalWiki($owner), WikiVoter::EDIT);
    }

    /** A User with an id: the rule refuses an unsaved account outright, so the stub needs one. */
    private function identifiedUser(int $id, array $roles): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);

        return $user;
    }

    private function personalWiki(User $owner): Wiki
    {
        $wiki = $this->createStub(Wiki::class);
        $wiki->method('getType')->willReturn(WikiType::Personal);
        $wiki->method('getOwner')->willReturn($owner);
        $wiki->method('getCreatedBy')->willReturn($owner);
        $wiki->method('getMemberIds')->willReturn([]);
        $wiki->method('getMemberRoles')->willReturn([]);
        $wiki->method('getPrograms')->willReturn(new ArrayCollection());

        return $wiki;
    }
}

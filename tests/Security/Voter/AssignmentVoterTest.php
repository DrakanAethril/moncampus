<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Assignment;
use App\Entity\User;
use App\Security\Voter\AssignmentVoter;
use App\Service\AssignmentAudienceResolver;

/**
 * MANAGE is an authorship decision; SUBMIT is an audience decision. The two must not bleed into
 * each other - a student in the audience may submit but must never manage - and neither of them
 * knows what a role is: a colleague, staff or an administrator manages nothing they did not give.
 */
class AssignmentVoterTest extends VoterTestCase
{
    private function voter(bool $inAudience): AssignmentVoter
    {
        $resolver = $this->createStub(AssignmentAudienceResolver::class);
        $resolver->method('isInAudience')->willReturn($inAudience);

        return new AssignmentVoter($resolver);
    }

    private function assignmentGivenBy(?User $author): Assignment
    {
        $assignment = $this->createStub(Assignment::class);
        $assignment->method('getCreatedBy')->willReturn($author);

        return $assignment;
    }

    public function testOnlyTheAuthorManages(): void
    {
        $author = $this->user();
        $colleague = $this->user();

        $this->assertGranted($this->voter(false), $author, $this->assignmentGivenBy($author), AssignmentVoter::MANAGE);
        $this->assertDenied($this->voter(false), $colleague, $this->assignmentGivenBy($author), AssignmentVoter::MANAGE);
    }

    public function testAdministratorDoesNotManageSomebodyElsesWork(): void
    {
        $author = $this->user();
        $admin = $this->user(['ROLE_ADMIN', 'ROLE_STAFF']);

        $this->assertDenied($this->voter(true), $admin, $this->assignmentGivenBy($author), AssignmentVoter::MANAGE);
    }

    public function testAnAuthorlessAssignmentIsNobodysToManage(): void
    {
        $this->assertDenied($this->voter(false), $this->user(), $this->assignmentGivenBy(null), AssignmentVoter::MANAGE);
    }

    public function testSubmitFollowsTheAudienceOnly(): void
    {
        $author = $this->user();

        $this->assertGranted($this->voter(true), $this->user(), $this->assignmentGivenBy($author), AssignmentVoter::SUBMIT);
        $this->assertDenied($this->voter(false), $author, $this->assignmentGivenBy($author), AssignmentVoter::SUBMIT);
    }

    public function testAnonymousIsDeniedAndForeignAttributesAreLeftAlone(): void
    {
        $assignment = $this->assignmentGivenBy($this->user());

        $this->assertDenied($this->voter(true), null, $assignment, AssignmentVoter::MANAGE);
        $this->assertAbstains($this->voter(true), $this->user(), $assignment, 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), AssignmentVoter::MANAGE);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Assignment;
use App\Security\StructureAccessChecker;
use App\Security\Voter\AssignmentVoter;
use App\Service\AssignmentAudienceResolver;

/**
 * MANAGE is a staff decision; SUBMIT is an audience decision. The two must not bleed into each
 * other - a student in the audience may submit but must never manage.
 */
class AssignmentVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff, bool $inAudience): AssignmentVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);
        $resolver = $this->createStub(AssignmentAudienceResolver::class);
        $resolver->method('isInAudience')->willReturn($inAudience);

        return new AssignmentVoter($checker, $resolver);
    }

    public function testStaffManagesButAudienceAloneDoesNot(): void
    {
        $assignment = $this->createStub(Assignment::class);

        $this->assertGranted($this->voter(true, false), $this->user(), $assignment, AssignmentVoter::MANAGE);
        $this->assertDenied($this->voter(false, true), $this->user(), $assignment, AssignmentVoter::MANAGE);
    }

    public function testSubmitFollowsTheAudienceOnly(): void
    {
        $assignment = $this->createStub(Assignment::class);

        $this->assertGranted($this->voter(false, true), $this->user(), $assignment, AssignmentVoter::SUBMIT);
        $this->assertDenied($this->voter(true, false), $this->user(), $assignment, AssignmentVoter::SUBMIT);
    }

    public function testAnonymousIsDeniedAndForeignAttributesAreLeftAlone(): void
    {
        $assignment = $this->createStub(Assignment::class);

        $this->assertDenied($this->voter(true, true), null, $assignment, AssignmentVoter::MANAGE);
        $this->assertAbstains($this->voter(true, true), $this->user(), $assignment, 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true, true), $this->user(), new \stdClass(), AssignmentVoter::MANAGE);
    }
}

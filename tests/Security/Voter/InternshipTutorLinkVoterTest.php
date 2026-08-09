<?php

namespace App\Tests\Security\Voter;

use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Security\Voter\InternshipTutorLinkVoter;

/**
 * The tightest Voter in the app: an external tutor may only ever evaluate their own link, and
 * holding ROLE_TUTOR alone is not enough - see project_livret_alternant_tutor_access.
 */
class InternshipTutorLinkVoterTest extends VoterTestCase
{
    private function link(?User $tutor): InternshipTutorLink
    {
        $link = $this->createStub(InternshipTutorLink::class);
        $link->method('getTutor')->willReturn($tutor);

        return $link;
    }

    public function testTutorEvaluatesOnlyTheirOwnLink(): void
    {
        $tutor = $this->user(['ROLE_USER', 'ROLE_TUTOR'], 'tutor');
        $other = $this->user(['ROLE_USER', 'ROLE_TUTOR'], 'other.tutor');

        $this->assertGranted(new InternshipTutorLinkVoter(), $tutor, $this->link($tutor), InternshipTutorLinkVoter::EVALUATE);
        $this->assertDenied(new InternshipTutorLinkVoter(), $other, $this->link($tutor), InternshipTutorLinkVoter::EVALUATE);
    }

    /** Staff are handled by their own screens; this attribute is the tutor's self-service door. */
    public function testWithoutTheTutorRoleNobodyPasses(): void
    {
        $staff = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'boss');

        $this->assertDenied(new InternshipTutorLinkVoter(), $staff, $this->link($staff), InternshipTutorLinkVoter::EVALUATE);
        $this->assertDenied(new InternshipTutorLinkVoter(), null, $this->link(null), InternshipTutorLinkVoter::EVALUATE);
    }
}

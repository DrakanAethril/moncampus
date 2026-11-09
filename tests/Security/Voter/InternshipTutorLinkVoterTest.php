<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Security\Voter\InternshipTutorLinkVoter;

/**
 * The tightest Voter in the app: an external tutor may only ever evaluate their own link, and
 * holding ROLE_TUTOR alone is not enough - see project_livret_alternant_tutor_access. « Their own »
 * is the principal's or the secondary tutor's: both hold the booklet with the same rights.
 */
class InternshipTutorLinkVoterTest extends VoterTestCase
{
    private function link(?User $tutor, ?User $secondaryTutor = null): InternshipTutorLink
    {
        return (new InternshipTutorLink($this->createStub(Program::class)))
            ->setTutor($tutor)
            ->setSecondaryTutor($secondaryTutor);
    }

    public function testTutorEvaluatesOnlyTheirOwnLink(): void
    {
        $tutor = $this->user(['ROLE_USER', 'ROLE_TUTOR'], 'tutor');
        $other = $this->user(['ROLE_USER', 'ROLE_TUTOR'], 'other.tutor');

        $this->assertGranted(new InternshipTutorLinkVoter(), $tutor, $this->link($tutor), InternshipTutorLinkVoter::EVALUATE);
        $this->assertDenied(new InternshipTutorLinkVoter(), $other, $this->link($tutor), InternshipTutorLinkVoter::EVALUATE);
    }

    public function testTheSecondaryTutorHasTheSameDoorAsThePrincipal(): void
    {
        $principal = $this->user(['ROLE_USER', 'ROLE_TUTOR'], 'tutor');
        $secondary = $this->user(['ROLE_USER', 'ROLE_TUTOR'], 'second.tutor');
        $other = $this->user(['ROLE_USER', 'ROLE_TUTOR'], 'other.tutor');
        $link = $this->link($principal, $secondary);

        $this->assertGranted(new InternshipTutorLinkVoter(), $principal, $link, InternshipTutorLinkVoter::EVALUATE);
        $this->assertGranted(new InternshipTutorLinkVoter(), $secondary, $link, InternshipTutorLinkVoter::EVALUATE);
        $this->assertDenied(new InternshipTutorLinkVoter(), $other, $link, InternshipTutorLinkVoter::EVALUATE);
    }

    /** The role is asked of the secondary tutor too: a staff account named there gets nothing here. */
    public function testASecondaryTutorWithoutTheTutorRoleDoesNotPass(): void
    {
        $staff = $this->user(['ROLE_USER', 'ROLE_STAFF'], 'staff');

        $this->assertDenied(new InternshipTutorLinkVoter(), $staff, $this->link($this->user(['ROLE_USER', 'ROLE_TUTOR'], 'tutor'), $staff), InternshipTutorLinkVoter::EVALUATE);
    }

    /** Staff are handled by their own screens; this attribute is the tutor's self-service door. */
    public function testWithoutTheTutorRoleNobodyPasses(): void
    {
        $staff = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'boss');

        $this->assertDenied(new InternshipTutorLinkVoter(), $staff, $this->link($staff), InternshipTutorLinkVoter::EVALUATE);
        $this->assertDenied(new InternshipTutorLinkVoter(), null, $this->link(null), InternshipTutorLinkVoter::EVALUATE);
    }
}

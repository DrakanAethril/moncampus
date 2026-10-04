<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\EcfBooklet;
use App\Security\Voter\EcfBookletVoter;

/**
 * The ECF booklet is read by the administration and written by administrators alone. The two
 * refusals worth pinning: staff read but never write or sign, and the people the booklet is about -
 * the student, the tutor, the teachers - see nothing of it.
 */
class EcfBookletVoterTest extends VoterTestCase
{
    private function booklet(): EcfBooklet
    {
        return new EcfBooklet($this->user(['ROLE_STUDENT'], 'candidate'), 'TP-01281', '04');
    }

    public function testAdministratorReadsWritesAndSigns(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);
        foreach ([EcfBookletVoter::VIEW, EcfBookletVoter::EDIT, EcfBookletVoter::SIGN] as $attribute) {
            $this->assertGranted(new EcfBookletVoter(), $admin, $this->booklet(), $attribute);
        }
    }

    public function testStaffReadOnly(): void
    {
        foreach (['ROLE_STAFF', 'ROLE_STAFF-LEAD'] as $role) {
            $staff = $this->user([$role]);
            $this->assertGranted(new EcfBookletVoter(), $staff, $this->booklet(), EcfBookletVoter::VIEW);
            $this->assertDenied(new EcfBookletVoter(), $staff, $this->booklet(), EcfBookletVoter::EDIT);
            $this->assertDenied(new EcfBookletVoter(), $staff, $this->booklet(), EcfBookletVoter::SIGN);
        }
    }

    public function testEveryoneElseSeesNothing(): void
    {
        foreach (['ROLE_TEACHER', 'ROLE_STUDENT', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH'] as $role) {
            foreach ([EcfBookletVoter::VIEW, EcfBookletVoter::EDIT, EcfBookletVoter::SIGN] as $attribute) {
                $this->assertDenied(new EcfBookletVoter(), $this->user([$role]), $this->booklet(), $attribute, $role.' '.$attribute);
            }
        }
        $this->assertDenied(new EcfBookletVoter(), null, $this->booklet(), EcfBookletVoter::VIEW);
    }

    public function testAbstainsOnOtherSubjectsAndAttributes(): void
    {
        $this->assertAbstains(new EcfBookletVoter(), $this->user(['ROLE_ADMIN']), new \stdClass(), EcfBookletVoter::VIEW);
        $this->assertAbstains(new EcfBookletVoter(), $this->user(['ROLE_ADMIN']), $this->booklet(), 'SOMETHING_ELSE');
    }

    public function testTheAdministrationOffersTheBookletToTheStudent(): void
    {
        foreach (['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'] as $role) {
            $this->assertGranted(new EcfBookletVoter(), $this->user([$role]), $this->booklet(), EcfBookletVoter::OFFER, $role);
        }
        foreach (['ROLE_TEACHER', 'ROLE_STUDENT', 'ROLE_TUTOR'] as $role) {
            $this->assertDenied(new EcfBookletVoter(), $this->user([$role]), $this->booklet(), EcfBookletVoter::OFFER, $role);
        }
    }

    public function testTheStudentReadsTheirOwnOfferedBookletAndSignsItOnceWhileClosed(): void
    {
        $student = $this->user(['ROLE_STUDENT'], 'candidate');
        $booklet = new EcfBooklet($student, 'TP-01281', '04');
        $this->assertDenied(new EcfBookletVoter(), $student, $booklet, EcfBookletVoter::CANDIDATE_READ, 'not offered yet');

        $booklet->setClosedAt(new \DateTimeImmutable())->offer($this->user(['ROLE_STAFF']), new \DateTimeImmutable());
        $this->assertGranted(new EcfBookletVoter(), $student, $booklet, EcfBookletVoter::CANDIDATE_READ);
        $this->assertGranted(new EcfBookletVoter(), $student, $booklet, EcfBookletVoter::CANDIDATE_SIGN);
        $this->assertDenied(new EcfBookletVoter(), $this->user(['ROLE_STUDENT'], 'classmate'), $booklet, EcfBookletVoter::CANDIDATE_READ, 'another student');
        $this->assertDenied(new EcfBookletVoter(), $this->user(['ROLE_ADMIN']), $booklet, EcfBookletVoter::CANDIDATE_SIGN, 'nobody signs for the student');

        $booklet->signAsCandidate('Lefèvre Hugo', new \DateTimeImmutable());
        $this->assertGranted(new EcfBookletVoter(), $student, $booklet, EcfBookletVoter::CANDIDATE_READ);
        $this->assertDenied(new EcfBookletVoter(), $student, $booklet, EcfBookletVoter::CANDIDATE_SIGN, 'signed once');
    }
}

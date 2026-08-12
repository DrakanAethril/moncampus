<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Program;
use App\Entity\SequenceInstance;
use App\Enum\ContentVisibility;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SequenceInstanceVoter;
use App\Service\AccessConditionGate;

/**
 * Reading a sequence of the course space.
 *
 * Two conditions stack for a student and only one for a teacher, which is the whole point: teaching
 * staff must be able to look at what they have not published yet, otherwise nobody could ever
 * proof-read a sequence before opening it.
 */
class SequenceInstanceVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff, bool $isProgramTeacher, bool $programVisible, bool $accessOpen = true): SequenceInstanceVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);
        $checker->method('isProgramTeacher')->willReturn($isProgramTeacher);
        $checker->method('isProgramVisible')->willReturn($programVisible);

        $gate = $this->createStub(AccessConditionGate::class);
        $gate->method('isOpen')->willReturn($accessOpen);

        return new SequenceInstanceVoter($checker, $gate);
    }

    private function sequence(ContentVisibility $visibility, ?string $publishedAt = null): SequenceInstance
    {
        $sequence = $this->createStub(SequenceInstance::class);
        $sequence->method('getProgram')->willReturn($this->createStub(Program::class));
        $sequence->method('getStudentVisibility')->willReturn($visibility);
        $sequence->method('isVisibleToStudentsAt')->willReturn(
            $visibility->isVisibleAt(
                null === $publishedAt ? null : new \DateTimeImmutable($publishedAt),
                new \DateTimeImmutable(),
            ),
        );

        return $sequence;
    }

    public function testStaffSeeUnpublishedSequences(): void
    {
        $this->assertGranted(
            $this->voter(true, false, false),
            $this->user(['ROLE_ADMIN']),
            $this->sequence(ContentVisibility::Hidden),
            SequenceInstanceVoter::VIEW,
        );
    }

    public function testAProgramTeacherSeesTheirOwnUnpublishedSequences(): void
    {
        $this->assertGranted(
            $this->voter(false, true, true),
            $this->user(['ROLE_USER', 'ROLE_TEACHER']),
            $this->sequence(ContentVisibility::Hidden),
            SequenceInstanceVoter::VIEW,
        );
    }

    public function testAStudentOfTheProgramSeesAPublishedSequence(): void
    {
        $this->assertGranted(
            $this->voter(false, false, true),
            $this->user(['ROLE_USER', 'ROLE_STUDENT']),
            $this->sequence(ContentVisibility::Published),
            SequenceInstanceVoter::VIEW,
        );
    }

    /** The default state of every sequence: a student must not reach it before it is published. */
    public function testAStudentNeverSeesAHiddenSequence(): void
    {
        $this->assertDenied(
            $this->voter(false, false, true),
            $this->user(['ROLE_USER', 'ROLE_STUDENT']),
            $this->sequence(ContentVisibility::Hidden),
            SequenceInstanceVoter::VIEW,
        );
    }

    public function testAStudentWaitsForAScheduledSequenceToOpen(): void
    {
        $this->assertDenied(
            $this->voter(false, false, true),
            $this->user(['ROLE_USER', 'ROLE_STUDENT']),
            $this->sequence(ContentVisibility::Scheduled, '2099-01-01 08:00:00'),
            SequenceInstanceVoter::VIEW,
        );

        $this->assertGranted(
            $this->voter(false, false, true),
            $this->user(['ROLE_USER', 'ROLE_STUDENT']),
            $this->sequence(ContentVisibility::Scheduled, '2020-01-01 08:00:00'),
            SequenceInstanceVoter::VIEW,
        );
    }

    /**
     * A published sequence held by an access condition: the row is drawn greyed, so its address is
     * one click away from being typed by hand, and the voter is where that is settled rather than
     * in the template that happened to grey it.
     */
    public function testAStudentCannotOpenASequenceItsConditionStillHolds(): void
    {
        $this->assertDenied(
            $this->voter(false, false, true, accessOpen: false),
            $this->user(['ROLE_USER', 'ROLE_STUDENT']),
            $this->sequence(ContentVisibility::Published),
            SequenceInstanceVoter::VIEW,
        );
    }

    /** A teacher of the class reads straight through it, exactly as through publication. */
    public function testATeacherReadsThroughAnAccessCondition(): void
    {
        $this->assertGranted(
            $this->voter(false, true, true, accessOpen: false),
            $this->user(['ROLE_USER', 'ROLE_TEACHER']),
            $this->sequence(ContentVisibility::Published),
            SequenceInstanceVoter::VIEW,
        );
    }

    /** Publication never widens the audience: a published sequence stays inside its Program. */
    public function testSomebodyOutsideTheProgramSeesNothingHoweverPublished(): void
    {
        $this->assertDenied(
            $this->voter(false, false, false),
            $this->user(['ROLE_USER', 'ROLE_STUDENT']),
            $this->sequence(ContentVisibility::Published),
            SequenceInstanceVoter::VIEW,
        );
    }

    public function testAnonymousVisitorsAreDenied(): void
    {
        $this->assertDenied(
            $this->voter(false, false, true),
            null,
            $this->sequence(ContentVisibility::Published),
            SequenceInstanceVoter::VIEW,
        );
    }

    public function testTheVoterStaysOutOfOtherDecisions(): void
    {
        $this->assertAbstains(
            $this->voter(true, true, true),
            $this->user(['ROLE_ADMIN']),
            $this->sequence(ContentVisibility::Published),
            'SOME_OTHER_ATTRIBUTE',
        );

        $this->assertAbstains(
            $this->voter(true, true, true),
            $this->user(['ROLE_ADMIN']),
            new \stdClass(),
            SequenceInstanceVoter::VIEW,
        );
    }
}

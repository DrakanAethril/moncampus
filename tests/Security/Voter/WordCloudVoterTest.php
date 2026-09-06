<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Program;
use App\Entity\WordCloud;
use App\Security\StructureAccessChecker;
use App\Security\Voter\WordCloudVoter;
use App\Service\WordCloud\WordCloudAudience;
use App\Service\WordCloud\WordCloudSchedule;
use App\Service\WordCloud\WordCloudWindow;

/**
 * Two attributes that must never collapse into each other:
 *
 *  - PILOT   a teacher of the class (staff included) - the whole teacher side
 *  - SUBMIT  a student of the audience, and only while the cloud is open
 *
 * The three pairings pinned below are the ones that would go unnoticed: staff writing into a class
 * cloud, a targeted student piloting it, and a submission surviving the closing time.
 */
class WordCloudVoterTest extends VoterTestCase
{
    public function testATeacherOfTheClassPilots(): void
    {
        $voter = $this->voter(isProgramTeacher: true, inAudience: false, open: true);

        $this->assertGranted($voter, $this->user(['ROLE_TEACHER'], 'teacher'), $this->cloud(), WordCloudVoter::PILOT);
    }

    public function testSomebodyWhoDoesNotTeachTheClassDoesNot(): void
    {
        $voter = $this->voter(isProgramTeacher: false, inAudience: false, open: true);

        $this->assertDenied($voter, $this->user(['ROLE_TEACHER'], 'other'), $this->cloud(), WordCloudVoter::PILOT);
    }

    /** A student of the class is exactly who the tool is run at, and never who runs it. */
    public function testAStudentOfTheAudienceStillDoesNotPilot(): void
    {
        $voter = $this->voter(isProgramTeacher: false, inAudience: true, open: true);

        $this->assertDenied($voter, $this->user(['ROLE_STUDENT'], 'student'), $this->cloud(), WordCloudVoter::PILOT);
    }

    public function testATargetedStudentSubmitsWhileTheCloudIsOpen(): void
    {
        $voter = $this->voter(isProgramTeacher: false, inAudience: true, open: true);

        $this->assertGranted($voter, $this->user(['ROLE_STUDENT'], 'student'), $this->cloud(), WordCloudVoter::SUBMIT);
    }

    /** Outside the period the word is refused, not merely un-offered. */
    public function testTheSameStudentIsRefusedOnceTheCloudIsClosed(): void
    {
        $voter = $this->voter(isProgramTeacher: false, inAudience: true, open: false);

        $this->assertDenied($voter, $this->user(['ROLE_STUDENT'], 'student'), $this->cloud(), WordCloudVoter::SUBMIT);
    }

    public function testAStudentOutsideTheTargetedOptionsDoesNotSubmit(): void
    {
        $voter = $this->voter(isProgramTeacher: false, inAudience: false, open: true);

        $this->assertDenied($voter, $this->user(['ROLE_STUDENT'], 'student'), $this->cloud(), WordCloudVoter::SUBMIT);
    }

    /**
     * The one asymmetry worth stating: piloting is staff-bypassed and writing is not. An
     * administrator is not a member of the class, and their word would be counted in the class's
     * own cloud.
     */
    public function testStaffPilotAnythingAndWriteIntoNothing(): void
    {
        $voter = $this->voter(isProgramTeacher: true, inAudience: false, open: true);
        $staff = $this->user(['ROLE_ADMIN'], 'admin');

        $this->assertGranted($voter, $staff, $this->cloud(), WordCloudVoter::PILOT);
        $this->assertDenied($voter, $staff, $this->cloud(), WordCloudVoter::SUBMIT);
    }

    public function testAnAnonymousVisitorGetsNothing(): void
    {
        $voter = $this->voter(isProgramTeacher: true, inAudience: true, open: true);

        $this->assertDenied($voter, null, $this->cloud(), WordCloudVoter::PILOT);
        $this->assertDenied($voter, null, $this->cloud(), WordCloudVoter::SUBMIT);
    }

    /** A cloud with no class attached decides nothing rather than defaulting to open. */
    public function testACloudWithoutAProgramIsRefusedOutright(): void
    {
        $voter = $this->voter(isProgramTeacher: true, inAudience: true, open: true);
        $cloud = $this->createStub(WordCloud::class);
        $cloud->method('getProgram')->willReturn(null);
        $cloud->method('window')->willReturn(new WordCloudWindow(null, null));

        $this->assertDenied($voter, $this->user(['ROLE_TEACHER'], 'teacher'), $cloud, WordCloudVoter::PILOT);
    }

    public function testItStaysOutOfOtherDecisions(): void
    {
        $voter = $this->voter(isProgramTeacher: true, inAudience: true, open: true);

        $this->assertAbstains($voter, $this->user(['ROLE_TEACHER'], 'teacher'), $this->cloud(), 'SOMETHING_ELSE');
        $this->assertAbstains($voter, $this->user(['ROLE_TEACHER'], 'teacher'), new \stdClass(), WordCloudVoter::PILOT);
    }

    private function voter(bool $isProgramTeacher, bool $inAudience, bool $open): WordCloudVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isProgramTeacher')->willReturn($isProgramTeacher);

        $audience = $this->createStub(WordCloudAudience::class);
        $audience->method('includes')->willReturn($inAudience);

        $schedule = $this->createStub(WordCloudSchedule::class);
        $schedule->method('isOpen')->willReturn($open);

        return new WordCloudVoter($checker, $audience, $schedule);
    }

    private function cloud(): WordCloud
    {
        $cloud = $this->createStub(WordCloud::class);
        $cloud->method('getProgram')->willReturn($this->createStub(Program::class));
        $cloud->method('window')->willReturn(new WordCloudWindow(null, null));

        return $cloud;
    }
}

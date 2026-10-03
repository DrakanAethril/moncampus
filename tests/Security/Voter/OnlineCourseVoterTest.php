<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\OnlineCourse;
use App\Enum\OnlineCourseStatus;
use App\Security\Voter\OnlineCourseVoter;

/**
 * « Qui voit quoi » on an online course (design/validated/cours-en-ligne.md, §3): a draft is its
 * author's, a public course is anybody's - a visitor with no account included - and an
 * administrator holds exactly one gesture on somebody else's course, taking it offline.
 */
class OnlineCourseVoterTest extends VoterTestCase
{
    private const array WRITES = [OnlineCourseVoter::EDIT, OnlineCourseVoter::PUBLISH];

    public function testADraftIsItsAuthorsAlone(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        $course = new OnlineCourse($owner, 'Les jointures SQL', 'les-jointures-sql');

        foreach ([OnlineCourseVoter::VIEW, ...self::WRITES, OnlineCourseVoter::DELETE] as $attribute) {
            $this->assertGranted(new OnlineCourseVoter(), $owner, $course, $attribute);
        }

        foreach ([$this->user(['ROLE_TEACHER'], 'colleague'), $this->user(['ROLE_ADMIN'], 'admin'), null] as $other) {
            foreach ([OnlineCourseVoter::VIEW, ...self::WRITES, OnlineCourseVoter::UNPUBLISH, OnlineCourseVoter::DELETE] as $attribute) {
                $this->assertDenied(new OnlineCourseVoter(), $other, $course, $attribute);
            }
        }
    }

    public function testAPublicCourseIsReadByAnybodyAndWrittenByItsAuthor(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        $course = (new OnlineCourse($owner, 'Les jointures SQL', 'les-jointures-sql'))->setStatus(OnlineCourseStatus::PublicCourse);

        foreach ([$owner, $this->user(['ROLE_STUDENT'], 'student'), $this->user(['ROLE_ADMIN'], 'admin'), null] as $reader) {
            $this->assertGranted(new OnlineCourseVoter(), $reader, $course, OnlineCourseVoter::VIEW);
        }

        foreach ([$this->user(['ROLE_TEACHER'], 'colleague'), $this->user(['ROLE_ADMIN'], 'admin'), null] as $other) {
            foreach (self::WRITES as $attribute) {
                $this->assertDenied(new OnlineCourseVoter(), $other, $course, $attribute);
            }
        }
    }

    public function testAnAdministratorTakesACourseOfflineAndNothingElse(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        $admin = $this->user(['ROLE_ADMIN'], 'admin');
        $course = (new OnlineCourse($owner, 'Les jointures SQL', 'les-jointures-sql'))->setStatus(OnlineCourseStatus::PublicCourse);

        $this->assertGranted(new OnlineCourseVoter(), $admin, $course, OnlineCourseVoter::UNPUBLISH);
        $this->assertGranted(new OnlineCourseVoter(), $owner, $course, OnlineCourseVoter::UNPUBLISH);
        $this->assertDenied(new OnlineCourseVoter(), $this->user(['ROLE_TEACHER'], 'colleague'), $course, OnlineCourseVoter::UNPUBLISH);
        $this->assertDenied(new OnlineCourseVoter(), $admin, $course, OnlineCourseVoter::EDIT);
        $this->assertDenied(new OnlineCourseVoter(), $admin, $course, OnlineCourseVoter::DELETE);
    }

    public function testAnOnlineCourseIsTakenOfflineBeforeItIsDeleted(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        $course = (new OnlineCourse($owner, 'Les jointures SQL', 'les-jointures-sql'))->setStatus(OnlineCourseStatus::PublicCourse);

        $this->assertDenied(new OnlineCourseVoter(), $owner, $course, OnlineCourseVoter::DELETE);

        $course->setStatus(OnlineCourseStatus::Draft);
        $this->assertGranted(new OnlineCourseVoter(), $owner, $course, OnlineCourseVoter::DELETE);
    }

    public function testACourseReservedForPathsIsNotOpenedToItsReadersHere(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        $course = (new OnlineCourse($owner, 'SELECT et filtres', 'select-et-filtres'))->setStatus(OnlineCourseStatus::PathOnly);

        $this->assertGranted(new OnlineCourseVoter(), $owner, $course, OnlineCourseVoter::VIEW);
        $this->assertGranted(new OnlineCourseVoter(), $this->user(['ROLE_ADMIN'], 'admin'), $course, OnlineCourseVoter::VIEW);
        $this->assertDenied(new OnlineCourseVoter(), $this->user(['ROLE_STUDENT'], 'student'), $course, OnlineCourseVoter::VIEW);
        $this->assertDenied(new OnlineCourseVoter(), null, $course, OnlineCourseVoter::VIEW);
    }

    public function testItStaysOutOfOtherSubjects(): void
    {
        $this->assertAbstains(new OnlineCourseVoter(), $this->user(['ROLE_TEACHER']), new \stdClass(), OnlineCourseVoter::VIEW);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\LearningPath;
use App\Enum\LearningPathStatus;
use App\Security\Voter\LearningPathVoter;

/**
 * « Qui voit quoi » on a learning path (design/validated/cours-en-ligne.md, §3): an account follows
 * a published path, nobody without one does; its author alone composes it and reads its follow-up -
 * the administrator included in the « nobody else ».
 */
class LearningPathVoterTest extends VoterTestCase
{
    public function testAPublishedPathIsFollowedByAnAccountAndNeverWithoutOne(): void
    {
        $path = (new LearningPath($this->user(['ROLE_TEACHER'], 'owner'), 'SQL'))->setStatus(LearningPathStatus::Published);

        $this->assertGranted(new LearningPathVoter(), $this->user(['ROLE_STUDENT'], 'student'), $path, LearningPathVoter::FOLLOW);
        $this->assertDenied(new LearningPathVoter(), null, $path, LearningPathVoter::FOLLOW);
    }

    public function testADraftIsFollowedByItsAuthorAlone(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        $path = new LearningPath($owner, 'SQL');

        $this->assertGranted(new LearningPathVoter(), $owner, $path, LearningPathVoter::FOLLOW);
        $this->assertDenied(new LearningPathVoter(), $this->user(['ROLE_STUDENT'], 'student'), $path, LearningPathVoter::FOLLOW);
        $this->assertDenied(new LearningPathVoter(), $this->user(['ROLE_ADMIN'], 'admin'), $path, LearningPathVoter::FOLLOW);
    }

    public function testTheFollowUpIsReadByTheAuthorAndNotByAnAdministrator(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        $path = (new LearningPath($owner, 'SQL'))->setStatus(LearningPathStatus::Published);

        $this->assertGranted(new LearningPathVoter(), $owner, $path, LearningPathVoter::TRACK);
        $this->assertGranted(new LearningPathVoter(), $owner, $path, LearningPathVoter::EDIT);
        foreach ([$this->user(['ROLE_ADMIN'], 'admin'), $this->user(['ROLE_TEACHER'], 'colleague')] as $other) {
            $this->assertDenied(new LearningPathVoter(), $other, $path, LearningPathVoter::TRACK);
            $this->assertDenied(new LearningPathVoter(), $other, $path, LearningPathVoter::EDIT);
        }
    }

    public function testAPublishedPathIsTakenOfflineBeforeItIsDeleted(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        $path = (new LearningPath($owner, 'SQL'))->setStatus(LearningPathStatus::Published);
        $this->assertDenied(new LearningPathVoter(), $owner, $path, LearningPathVoter::DELETE);

        $path->setStatus(LearningPathStatus::Draft);
        $this->assertGranted(new LearningPathVoter(), $owner, $path, LearningPathVoter::DELETE);
    }

    public function testItStaysOutOfOtherSubjects(): void
    {
        $this->assertAbstains(new LearningPathVoter(), $this->user(['ROLE_TEACHER']), new \stdClass(), LearningPathVoter::FOLLOW);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\SurveyCampaign;
use App\Entity\SurveySeries;
use App\Entity\SurveyTarget;
use App\Entity\SurveyTemplate;
use App\Entity\User;
use App\Repository\SurveyTargetRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SurveyVoter;

/**
 * The four doors of a survey, and the one that is deliberately shut to staff.
 *
 * EDIT/LAUNCH have no staff bypass at all: launching a survey under a colleague's name is not a
 * gesture the application offers. VIEW_RESULTS does have one, because a satisfaction survey is an
 * institution-wide object. RESPOND reads the frozen target and nothing else - no on-the-fly
 * audience recomputation, since the frozen target *is* the right to answer.
 *
 * And the case that matters most (design/validated/surveys.md §6): an admin on an anonymous
 * campaign gains nothing here. Anonymity is not a permission the Voter could lift - it is a
 * property of the data, and there is simply no name stored to show.
 */
class SurveyVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff, ?SurveyTarget $target = null): SurveyVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);

        $targets = $this->createStub(SurveyTargetRepository::class);
        $targets->method('findOneFor')->willReturn($target);

        return new SurveyVoter($checker, $targets);
    }

    private function template(?User $owner): SurveyTemplate
    {
        $template = $this->createStub(SurveyTemplate::class);
        $template->method('getOwner')->willReturn($owner);

        return $template;
    }

    private function campaign(?User $owner, bool $open = true, bool $anonymous = false): SurveyCampaign
    {
        $campaign = $this->createStub(SurveyCampaign::class);
        $campaign->method('getCreatedBy')->willReturn($owner);
        $campaign->method('isOpenNow')->willReturn($open);
        $campaign->method('isAnonymous')->willReturn($anonymous);

        return $campaign;
    }

    private function target(bool $responded): SurveyTarget
    {
        $target = $this->createStub(SurveyTarget::class);
        $target->method('hasResponded')->willReturn($responded);

        return $target;
    }

    public function testOwnerEditsAndLaunchesTheirOwnModel(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $voter = $this->voter(false);

        $this->assertGranted($voter, $owner, $this->template($owner), SurveyVoter::EDIT);
        $this->assertGranted($voter, $owner, $this->template($owner), SurveyVoter::LAUNCH);
    }

    /**
     * The asymmetry the design insists on: staff read every result, and edit or launch nobody
     * else's survey. Same shape as « enseignant référent », which is also not staff-bypassed.
     */
    public function testStaffNeverEditsNorLaunchesSomebodyElsesSurvey(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $staff = $this->user(['ROLE_USER', 'ROLE_STAFF'], 'staff');
        $voter = $this->voter(true);

        $this->assertDenied($voter, $staff, $this->template($owner), SurveyVoter::EDIT);
        $this->assertDenied($voter, $staff, $this->template($owner), SurveyVoter::LAUNCH);
    }

    public function testStaffReadsTheResultsOfAnybodysCampaign(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $staff = $this->user(['ROLE_USER', 'ROLE_STAFF'], 'staff');

        $this->assertGranted($this->voter(true), $staff, $this->campaign($owner), SurveyVoter::VIEW_RESULTS);
    }

    public function testAnotherTeacherReadsNothing(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');

        $this->assertDenied($this->voter(false), $other, $this->campaign($owner), SurveyVoter::VIEW_RESULTS);
    }

    public function testSeriesFollowsTheSameResultsRule(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $series = $this->createStub(SurveySeries::class);
        $series->method('getOwner')->willReturn($owner);

        $this->assertGranted($this->voter(false), $owner, $series, SurveyVoter::VIEW_RESULTS);
        $this->assertDenied($this->voter(false), $this->user(['ROLE_USER'], 'other'), $series, SurveyVoter::VIEW_RESULTS);
    }

    /**
     * An admin gains no more on an anonymous campaign than on a nominative one - and above all
     * gains nothing *because* it is anonymous. If this test ever needs an isAdmin() branch to pass,
     * the branch is the bug: the feature would have been emptied of its meaning.
     */
    public function testAnAdminGetsNoExtraDoorOnAnAnonymousCampaign(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $admin = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'admin');

        $anonymous = $this->campaign($owner, anonymous: true);
        $nominative = $this->campaign($owner);
        $voter = $this->voter(true);

        // Reading results is the same door in both cases - what changes is that the anonymous
        // campaign has no name to give, which is a matter of stored data, not of permission.
        $this->assertGranted($voter, $admin, $anonymous, SurveyVoter::VIEW_RESULTS);
        $this->assertGranted($voter, $admin, $nominative, SurveyVoter::VIEW_RESULTS);

        // And an admin still cannot edit or launch it.
        $this->assertDenied($voter, $admin, $anonymous, SurveyVoter::EDIT);
        $this->assertDenied($voter, $admin, $anonymous, SurveyVoter::LAUNCH);
    }

    public function testRespondingNeedsATargetRowAndNothingElse(): void
    {
        $student = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student');
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertGranted(
            $this->voter(false, $this->target(responded: false)),
            $student,
            $this->campaign($owner),
            SurveyVoter::RESPOND,
        );
    }

    public function testSomebodyOutsideTheFrozenTargetNeverAnswers(): void
    {
        $outsider = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'outsider');
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertDenied(
            $this->voter(false, null),
            $outsider,
            $this->campaign($owner),
            SurveyVoter::RESPOND,
        );
    }

    /** The double response is stopped here, by survey_target.responded_at - not by a unique index. */
    public function testAnsweringTwiceIsRefused(): void
    {
        $student = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student');
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertDenied(
            $this->voter(false, $this->target(responded: true)),
            $student,
            $this->campaign($owner),
            SurveyVoter::RESPOND,
        );
    }

    public function testAClosedCampaignIsNotAnswered(): void
    {
        $student = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student');
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertDenied(
            $this->voter(false, $this->target(responded: false)),
            $student,
            $this->campaign($owner, open: false),
            SurveyVoter::RESPOND,
        );
    }

    /** Even the owner does not "respond" to their own survey without being in the target. */
    public function testTheOwnerIsNotAutomaticallyARespondent(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertDenied($this->voter(true, null), $owner, $this->campaign($owner), SurveyVoter::RESPOND);
    }

    public function testAnonymousVisitorGetsNothing(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertDenied($this->voter(true, $this->target(false)), null, $this->campaign($owner), SurveyVoter::RESPOND);
        $this->assertDenied($this->voter(true), null, $this->template($owner), SurveyVoter::EDIT);
    }

    public function testItStaysOutOfDecisionsThatAreNotItsOwn(): void
    {
        $user = $this->user(['ROLE_USER'], 'someone');

        $this->assertAbstains($this->voter(true), $user, new \stdClass(), SurveyVoter::EDIT);
        $this->assertAbstains($this->voter(true), $user, $this->template($user), 'SOME_OTHER_ATTRIBUTE');
        // RESPOND is about a campaign; a model is not something one answers.
        $this->assertAbstains($this->voter(true), $user, $this->template($user), SurveyVoter::RESPOND);
    }
}

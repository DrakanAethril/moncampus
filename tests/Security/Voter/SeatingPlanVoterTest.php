<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Program;
use App\Entity\SeatingPlan;
use App\Entity\User;
use App\Security\Voter\SeatingPlanVoter;

/**
 * A seating plan is its author's: the colleagues it is shared with read it, and modify it only
 * when it was shared « en modification »; renaming, sharing and deleting stay the author's. Nobody
 * else - an administrator included - sees it at all.
 */
class SeatingPlanVoterTest extends VoterTestCase
{
    private function plan(User $owner): SeatingPlan
    {
        return new SeatingPlan($owner, $this->createStub(Program::class), 'SIO1 — Cours en B204', null, 'B204', []);
    }

    public function testTheAuthorDoesEverything(): void
    {
        $owner = $this->user(['ROLE_TEACHER'], 'owner');
        $plan = $this->plan($owner);

        foreach ([SeatingPlanVoter::VIEW, SeatingPlanVoter::EDIT, SeatingPlanVoter::MANAGE] as $attribute) {
            $this->assertGranted(new SeatingPlanVoter(), $owner, $plan, $attribute);
        }
    }

    public function testAColleagueItIsSharedWithReadsItOnly(): void
    {
        $colleague = $this->user(['ROLE_TEACHER'], 'colleague');
        $plan = $this->plan($this->user(['ROLE_TEACHER'], 'owner'))->shareWith([$colleague], false);

        $this->assertGranted(new SeatingPlanVoter(), $colleague, $plan, SeatingPlanVoter::VIEW);
        $this->assertDenied(new SeatingPlanVoter(), $colleague, $plan, SeatingPlanVoter::EDIT);
        $this->assertDenied(new SeatingPlanVoter(), $colleague, $plan, SeatingPlanVoter::MANAGE);
    }

    public function testSharedEnModificationAColleagueEditsButNeverManages(): void
    {
        $colleague = $this->user(['ROLE_TEACHER'], 'colleague');
        $plan = $this->plan($this->user(['ROLE_TEACHER'], 'owner'))->shareWith([$colleague], true);

        $this->assertGranted(new SeatingPlanVoter(), $colleague, $plan, SeatingPlanVoter::EDIT);
        $this->assertDenied(new SeatingPlanVoter(), $colleague, $plan, SeatingPlanVoter::MANAGE);
    }

    public function testNobodyElseNotEvenAnAdministrator(): void
    {
        $plan = $this->plan($this->user(['ROLE_TEACHER'], 'owner'))->shareWith([$this->user(['ROLE_TEACHER'], 'colleague')], true);

        foreach ([$this->user(['ROLE_TEACHER'], 'other'), $this->user(['ROLE_ADMIN'], 'admin')] as $other) {
            foreach ([SeatingPlanVoter::VIEW, SeatingPlanVoter::EDIT, SeatingPlanVoter::MANAGE] as $attribute) {
                $this->assertDenied(new SeatingPlanVoter(), $other, $plan, $attribute);
            }
        }
        $this->assertDenied(new SeatingPlanVoter(), null, $plan, SeatingPlanVoter::VIEW);
    }

    public function testItStaysOutOfOtherSubjects(): void
    {
        $this->assertAbstains(new SeatingPlanVoter(), $this->user(['ROLE_TEACHER']), new \stdClass(), SeatingPlanVoter::VIEW);
    }
}

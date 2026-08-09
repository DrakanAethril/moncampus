<?php

namespace App\Tests\Security\Voter;

use App\Entity\AudienceTargetable;
use App\Security\Voter\AudienceTargetableVoter;
use App\Service\AudienceResolver;

/**
 * A thin Voter: it delegates entirely to AudienceResolver. What is worth pinning is that it
 * delegates at all, and that an anonymous visitor never reaches the resolver.
 */
class AudienceTargetableVoterTest extends VoterTestCase
{
    private function voter(bool $visible): AudienceTargetableVoter
    {
        $resolver = $this->createStub(AudienceResolver::class);
        $resolver->method('isVisibleTo')->willReturn($visible);

        return new AudienceTargetableVoter($resolver);
    }

    public function testVerdictFollowsTheAudienceResolver(): void
    {
        $target = $this->createStub(AudienceTargetable::class);

        $this->assertGranted($this->voter(true), $this->user(), $target, AudienceTargetableVoter::VIEW);
        $this->assertDenied($this->voter(false), $this->user(), $target, AudienceTargetableVoter::VIEW);
    }

    public function testAnonymousNeverSeesAnything(): void
    {
        $this->assertDenied($this->voter(true), null, $this->createStub(AudienceTargetable::class), AudienceTargetableVoter::VIEW);
    }
}

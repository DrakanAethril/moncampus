<?php

namespace App\Security\Voter;

use App\Entity\AudienceTargetable;
use App\Entity\User;
use App\Service\AudienceResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Shared VIEW check for any App\Entity\AudienceTargetable (Announcement, AgendaEvent) - true
 * only if the current user is actually within the resolved audience. Unlike MessageThreadVoter,
 * there's no persistent per-recipient row to look up (see AudienceResolver's docblock on why
 * these two don't need one) - membership is resolved live on every check.
 */
class AudienceTargetableVoter extends Voter
{
    public const string VIEW = 'AUDIENCE_TARGETABLE_VIEW';

    public function __construct(
        private readonly AudienceResolver $audienceResolver,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof AudienceTargetable;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var AudienceTargetable $target */
        $target = $subject;
        $user = $token->getUser();

        return $user instanceof User && $this->audienceResolver->isVisibleTo($target, $user);
    }
}

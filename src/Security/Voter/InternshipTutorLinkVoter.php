<?php

namespace App\Security\Voter;

use App\Entity\InternshipTutorLink;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Scopes access to an InternshipTutorLink's evaluation form to the one ROLE_EXTERNAL user
 * actually linked as its tutor - the first Voter in this codebase, since every other feature
 * area so far only needed role-based (not per-object-owner) access checks.
 */
class InternshipTutorLinkVoter extends Voter
{
    public const string EVALUATE = 'INTERNSHIP_TUTOR_EVALUATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EVALUATE === $attribute && $subject instanceof InternshipTutorLink;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var InternshipTutorLink $tutorLink */
        $tutorLink = $subject;
        $user = $token->getUser();

        if (!$user instanceof User || !\in_array('ROLE_EXTERNAL', $user->getRoles(), true)) {
            return false;
        }

        return $tutorLink->getTutor() === $user;
    }
}

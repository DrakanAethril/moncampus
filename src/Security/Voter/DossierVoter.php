<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Dossier;
use App\Entity\User;
use App\Service\Dossier\DossierTargetResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what with a dossier documentaire.
 *
 * Four attributes, and the asymmetry between them is the design:
 *
 *  - **VIEW / EDIT / VALIDATE: the validateurs**, the créateur among them. A dossier is a shared
 *    object between the people who follow it - that is what adding somebody as validateur *means* -
 *    so a co-validateur adds a document and treats a dépôt exactly as the créateur does. There is
 *    no staff bypass: following a dossier is a decision somebody made, not a rank.
 *  - **DELETE: the créateur alone.** The one gesture that takes the dossier away from the others,
 *    so the others do not have it. Same asymmetry as SurveyVoter's owner-only EDIT.
 *  - **SUBMIT: being a cible of a published dossier**, and nothing else. Membership is recomputed
 *    (App\Service\Dossier\DossierTargetResolver), not frozen: a student who joins the class joins
 *    the dossier.
 *
 * `ROLE_ADMIN` short-circuits the first four, and only those: an administrator reads and manages any
 * dossier - they are the only role the feature is delivered to on the day it ships - but being an
 * administrator is not being a cible, and there is nothing for them to deposit.
 *
 * And, per the Proxmox lesson: a Voter never queries the AuthorizationChecker.
 */
class DossierVoter extends Voter
{
    public const string VIEW = 'DOSSIER_VIEW';
    public const string EDIT = 'DOSSIER_EDIT';
    public const string VALIDATE = 'DOSSIER_VALIDATE';
    public const string DELETE = 'DOSSIER_DELETE';
    public const string SUBMIT = 'DOSSIER_SUBMIT';

    public function __construct(
        private readonly DossierTargetResolver $targets,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::VALIDATE, self::DELETE, self::SUBMIT], true)
            && $subject instanceof Dossier;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$subject instanceof Dossier) {
            return false;
        }

        if (self::SUBMIT === $attribute) {
            return $subject->isPublished() && $this->targets->isTarget($subject, $user);
        }

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW, self::EDIT, self::VALIDATE => $subject->isValidator($user),
            self::DELETE => $subject->isCreator($user),
            default => false,
        };
    }
}

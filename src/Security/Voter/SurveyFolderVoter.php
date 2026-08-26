<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SurveyFolder;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A survey folder is its author's own classement: the owner alone.
 *
 * **No staff bypass**, and that is the one place this Voter deliberately differs from
 * App\Security\Voter\QuizFolderVoter, which grants staff. It follows SurveyVoter::EDIT, which grants
 * the owner and nobody else: staff who cannot edit a colleague's model have no business
 * reorganising the folders that model sits in. Granting the folder while refusing its content would
 * let staff rename and delete their way through a library they may not read.
 *
 * One attribute and not a VIEW/EDIT pair, for QuizFolderVoter's reason: a folder is never shared.
 */
class SurveyFolderVoter extends Voter
{
    public const string EDIT = 'SURVEY_FOLDER_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof SurveyFolder;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var SurveyFolder $folder */
        $folder = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $folder->getOwner() === $user;
    }
}

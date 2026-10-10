<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\TeacherDossier;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what with a dossier intervenant (design/validated/dossiers-intervenants.md, §3).
 *
 * The opposite posture to App\Security\Voter\DossierVoter, and that is why the two features do not
 * share a table: there nobody enters by rank, here **the administration enters by its role** -
 * administrators, staff and staff-lead manage and validate every dossier, with nobody to name.
 *
 *  - **MANAGE**: the administration. Composing, publishing, closing, deleting - and everything
 *    about a model, which has no other reader.
 *  - **VIEW**: the administration, and **the intervenant on their own dossier once it is handed to
 *    them** (published or closed). A draft is the administration's working copy.
 *  - **SUBMIT**: on a published dossier, its intervenant - and the administration, depositing a
 *    piece that reached it on paper or by mail. A closed dossier accepts nothing.
 *  - **REVIEW**: the administration, on a published dossier, **never on one's own**: a member of
 *    the staff who also teaches does not validate their own papers.
 *
 * Every other teacher is refused everything, and the controllers turn that into a 404: the pieces
 * are somebody's identity card and bank statement.
 */
class TeacherDossierVoter extends Voter
{
    public const string MANAGE = 'TEACHER_DOSSIER_MANAGE';
    public const string VIEW = 'TEACHER_DOSSIER_VIEW';
    public const string SUBMIT = 'TEACHER_DOSSIER_SUBMIT';
    public const string REVIEW = 'TEACHER_DOSSIER_REVIEW';

    public const array ADMINISTRATION = ['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::MANAGE, self::VIEW, self::SUBMIT, self::REVIEW], true)
            && $subject instanceof TeacherDossier;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$subject instanceof TeacherDossier) {
            return false;
        }

        // Read off the user, like every voter here: asking the authorization checker from inside a
        // voter re-enters the decision manager (see ProxmoxHostVoter).
        $administration = [] !== array_intersect(self::ADMINISTRATION, $user->getRoles());
        $holder = $subject->isHeldBy($user);

        return match ($attribute) {
            self::MANAGE => $administration,
            self::VIEW => $administration || ($holder && $subject->isReadableByTeacher()),
            self::SUBMIT => $subject->isPublished() && ($administration || $holder),
            self::REVIEW => $subject->isPublished() && $administration && !$holder,
            default => false,
        };
    }
}

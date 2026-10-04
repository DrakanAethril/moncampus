<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\EcfBooklet;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what on an ECF booklet (design/validated/ecf-booklet.md, R4).
 *
 *   - VIEW - read it on the alternance follow-up, read it online, download the PDF: the
 *            administration (ROLE_STAFF, ROLE_STAFF-LEAD) and administrators.
 *   - EDIT - write the cover, the sheets, the synthesis: administrators alone, for now.
 *   - SIGN - give or take back a visa: administrators alone. A visa is always the signer's own
 *            (App\Service\Ecf\EcfSigner): opening SIGN to the formation's teachers one day is a
 *            change here and nowhere else.
 *
 *   - OFFER - hand the closed booklet to the student for their signature, or withdraw it: the
 *            administration and administrators.
 *   - CANDIDATE_READ / CANDIDATE_SIGN - the booklet's own student, once it was offered to them:
 *            read it, and sign it « pour information » while it is closed and not yet signed.
 *
 * Teachers, tutors and other students are refused everything; the controllers turn that into a 404.
 */
class EcfBookletVoter extends Voter
{
    public const string VIEW = 'ECF_BOOKLET_VIEW';
    public const string EDIT = 'ECF_BOOKLET_EDIT';
    public const string SIGN = 'ECF_BOOKLET_SIGN';
    public const string OFFER = 'ECF_BOOKLET_OFFER';
    public const string CANDIDATE_READ = 'ECF_BOOKLET_CANDIDATE_READ';
    public const string CANDIDATE_SIGN = 'ECF_BOOKLET_CANDIDATE_SIGN';

    private const array READERS = ['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::SIGN, self::OFFER, self::CANDIDATE_READ, self::CANDIDATE_SIGN], true) && $subject instanceof EcfBooklet;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Read off the user, like every voter here: asking the authorization checker from inside a
        // voter re-enters the decision manager (see ProxmoxHostVoter).
        $roles = $user->getRoles();
        /** @var EcfBooklet $booklet */
        $booklet = $subject;
        $ownStudent = $booklet->getStudent() === $user && \in_array('ROLE_STUDENT', $roles, true);

        return match ($attribute) {
            self::VIEW, self::OFFER => [] !== array_intersect(self::READERS, $roles),
            self::CANDIDATE_READ => $ownStudent && $booklet->isOffered(),
            self::CANDIDATE_SIGN => $ownStudent && $booklet->isOffered() && $booklet->isClosed() && !$booklet->isCandidateSigned(),
            self::EDIT, self::SIGN => \in_array('ROLE_ADMIN', $roles, true),
            default => false,
        };
    }
}

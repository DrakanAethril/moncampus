<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who has a file library, and who may touch one (design/validated/file-library.md, "Access model").
 *
 * | Who | May |
 * |---|---|
 * | the owner | everything within **their own** library |
 * | `ROLE_ADMIN`, on someone else's | see the usage total and set the quota - and nothing more |
 * | everyone else | nothing |
 *
 * **There is no supervision rule here, and that is the opposite posture to the wiki's.** A wiki
 * holding a student's work is supervised because a teacher owes the student a look at it; a file
 * library holds its owner's own material and contains no student production. Staff do not read each
 * other's, `ROLE_STAFF-LEAD` does not, an admin does not. The admin row above is the quota card and
 * the whole of it: setting a limit needs a number, not a file manager.
 *
 * **Who has one** is `ROLE_TEACHER || isStaff()` - teachers and personnel, settled 2026-08-16 - and
 * it is written once, here. `StructureAccessChecker::isStaff()` is already
 * `ROLE_ADMIN || ROLE_STAFF || ROLE_STAFF-LEAD`, the same condition the Outils menu is gated on, so
 * the menu needs no condition of its own and the day the role list changes, one method changes.
 * `ROLE_TUTOR` and `ROLE_EXTERNAL` are excluded entirely, as they are from the wiki and messaging.
 *
 * One question is deliberately **not** answered here: what happens to a library when its owner
 * leaves the school. The recommendation - an admin door onto the library of a *deactivated* account
 * only - is written in the specification and not built.
 */
class FileLibraryVoter extends Voter
{
    /** Reading a library: its tree, its folders, its files. */
    public const string VIEW = 'FILE_LIBRARY_VIEW';

    /** Changing it: upload, rename, move, replace, delete, restore. */
    public const string EDIT = 'FILE_LIBRARY_EDIT';

    /** Linking one of its files into an assignment, a message, a wiki page (lot 4). */
    public const string LINK = 'FILE_LIBRARY_LINK';

    /**
     * Who has a library at all - `ROLE_TEACHER || isStaff()`, spelled out because this is asked of a
     * row and not of a session (see hasLibrary()).
     *
     * @var list<string>
     */
    private const array LIBRARY_ROLES = ['ROLE_TEACHER', 'ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!\in_array($attribute, [self::VIEW, self::EDIT, self::LINK], true)) {
            return false;
        }

        // Three shapes of subject, and they are three different questions: a node ("may I touch this
        // file"), a User ("may I open this person's library"), and null ("do I have one at all").
        return null === $subject || $subject instanceof FileLibraryNode || $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$this->hasLibrary($user)) {
            return false;
        }

        $owner = match (true) {
            $subject instanceof FileLibraryNode => $subject->getOwner(),
            $subject instanceof User => $subject,
            default => $user,
        };

        // The one rule. An admin holding ROLE_ADMIN also *owns* a library, and that is this line,
        // not a supervision power - the two never meet.
        return $owner->getId() === $user->getId();
    }

    /**
     * Teachers and personnel. Written once so the menu, the screens and the picker cannot drift
     * apart - and so a role list change is one method away.
     *
     * It reads the **roles of the row** rather than calling `StructureAccessChecker::isStaff()`,
     * which answers about whoever is logged in. That distinction is not academic here: the admin's
     * quota card asks this question about *somebody else*, and a session-scoped answer would have
     * offered a library to every account an admin opened. The list below is the same one -
     * `ROLE_TEACHER` plus what isStaff() covers.
     */
    public function hasLibrary(User $user): bool
    {
        return [] !== array_intersect(self::LIBRARY_ROLES, $user->getRoles());
    }
}

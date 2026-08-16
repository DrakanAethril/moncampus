<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Program;
use App\Entity\User;
use App\Entity\Wiki;
use App\Service\WikiAccess;
use App\Service\WikiSubject;
use App\Service\WikiViewer;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The only place that maps an App\Entity\Wiki onto App\Service\WikiAccess's primitives. The rule
 * itself lives there and is tested there; this class holds the entity graph and nothing else.
 *
 * WIKI_VIEW is a documented alias of WIKI_EDIT: there is no read-only access to a wiki today -
 * whoever can see one can edit it. Two attributes exist anyway so that the day a reader role
 * appears, no template has to change.
 */
class WikiVoter extends Voter
{
    public const string VIEW = 'WIKI_VIEW';
    public const string EDIT = 'WIKI_EDIT';
    public const string MANAGE = 'WIKI_MANAGE';
    public const string DELETE = 'WIKI_DELETE';

    public function __construct(private readonly WikiAccess $access)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::MANAGE, self::DELETE], true)
            && $subject instanceof Wiki;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Wiki $wiki */
        $wiki = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $viewer = new WikiViewer($user->getId(), $user->getRoles(), $this->isStudentOfAnAssignedProgram($wiki, $user));

        return match ($attribute) {
            self::VIEW, self::EDIT => $this->access->mayEdit($this->subjectOf($wiki), $viewer),
            self::MANAGE => $this->access->mayManage($this->subjectOf($wiki), $viewer),
            self::DELETE => $this->access->mayDelete($this->subjectOf($wiki), $viewer),
            default => false,
        };
    }

    private function subjectOf(Wiki $wiki): WikiSubject
    {
        $owner = $wiki->getOwner();

        return new WikiSubject(
            $wiki->getType(),
            $owner?->getId(),
            null !== $owner && \in_array('ROLE_STUDENT', $owner->getRoles(), true),
            $wiki->getCreatedBy()?->getId(),
            $wiki->getMemberIds(),
            $this->access->hasStudentAudience($wiki->getPrograms()->count(), $wiki->getMemberRoles()),
        );
    }

    /**
     * The one fact App\Service\WikiAccess cannot answer on its own - enrolment, not membership: a
     * wiki assigned to a whole class reaches every student of that class without naming any of
     * them.
     */
    private function isStudentOfAnAssignedProgram(Wiki $wiki, User $user): bool
    {
        foreach ($wiki->getPrograms() as $program) {
            /** @var Program $program */
            if ($program->getStudents()->contains($user)) {
                return true;
            }
        }

        return false;
    }
}

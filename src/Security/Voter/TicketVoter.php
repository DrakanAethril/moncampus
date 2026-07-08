<?php

namespace App\Security\Voter;

use App\Entity\Ticket;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Scopes access to a Ticket: handlers (admin/staff/staff-lead/support-tech) can view and manage
 * any ticket; everyone else can only view (and, via the reply form, comment on) their own. The
 * second per-object Voter in this codebase, after InternshipTutorLinkVoter.
 */
class TicketVoter extends Voter
{
    public const string VIEW = 'TICKET_VIEW';
    public const string MANAGE = 'TICKET_MANAGE';

    // Also referenced by TicketController to compute the assignable-users list and to decide
    // whether a viewer sees internal comments/the manage panel.
    /** @var list<string> */
    public const HANDLER_ROLES = ['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_SUPPORT-TECH'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE], true) && $subject instanceof Ticket;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Ticket $ticket */
        $ticket = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $isHandler = [] !== array_intersect(self::HANDLER_ROLES, $user->getRoles());

        if (self::MANAGE === $attribute) {
            return $isHandler;
        }

        return $isHandler || $ticket->getReporter() === $user;
    }
}
